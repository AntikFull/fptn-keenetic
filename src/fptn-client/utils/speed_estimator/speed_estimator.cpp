/*=============================================================================
Copyright (c) 2024-2026 Stas Skokov

Distributed under the MIT License (https://opensource.org/licenses/MIT)
=============================================================================*/

#include "fptn-client/utils/speed_estimator/speed_estimator.h"

#include <algorithm>
#include <atomic>
#include <condition_variable>
#include <memory>
#include <mutex>
#include <optional>
#include <random>
#include <string>
#include <thread>
#include <utility>
#include <vector>

#include <fmt/format.h>       // NOLINT(build/include_order)
#include <nlohmann/json.hpp>  // NOLINT(build/include_order)
#include <spdlog/spdlog.h>    // NOLINT(build/include_order)

#include "common/api/handle.h"

#include "fptn-protocol-lib/https/api_client/api_client.h"

using fptn::protocol::https::ApiClient;
using fptn::utils::speed_estimator::ServerInfo;

constexpr std::uint64_t kMaxTimeout = UINT64_MAX;

// Максимум одновременных проверок серверов. Каждая проверка — полное
// TLS-рукопожатие, поэтому на слабых устройствах (роутеры) их число нужно
// ограничивать: иначе старт клиента упирается в процессор.
constexpr std::size_t kMaxProbeConcurrency = 2;

namespace fptn::utils::speed_estimator {

std::uint64_t GetDownloadTimeMs(const ServerInfo& server,
    const std::string& sni,
    int timeout,
    const std::string& md5_fingerprint,
    fptn::protocol::https::CensorshipStrategy censorship_strategy) {
  try {
    auto const start = std::chrono::high_resolution_clock::now();
    ApiClient cli(
        server.host, server.port, sni, md5_fingerprint, censorship_strategy);
    auto const resp = cli.Get(common::api::kApiTestFileBinUrl, timeout);
    if (resp.code == 200) {
      auto const end = std::chrono::high_resolution_clock::now();
      const std::uint64_t ms =
          std::chrono::duration_cast<std::chrono::milliseconds>(end - start)
              .count();
      return ms;
    }
  } catch (const std::exception& ex) {
    SPDLOG_WARN("Exception in GetDownloadTimeMs: {}", ex.what());
  } catch (...) {
    SPDLOG_WARN("Unknown exception in GetDownloadTimeMs");
  }
  return kMaxTimeout;
}

ServerInfo FindFastestServer(const std::string& sni,
    const std::vector<ServerInfo>& servers,
    fptn::protocol::https::CensorshipStrategy censorship_strategy,
    int timeout_sec) {
  // randomly select half of the servers
  std::vector<ServerInfo> shuffled_servers = servers;
  std::random_device rd;
  std::mt19937 generator(rd());
  std::ranges::shuffle(shuffled_servers, generator);
  const std::size_t half_size =
      std::max<std::size_t>(1, shuffled_servers.size() / 2);
  std::vector<ServerInfo> selected_servers(
      shuffled_servers.begin(), shuffled_servers.begin() + half_size);

  struct State {
    std::mutex mtx;
    std::condition_variable cv;
    std::optional<ServerInfo> first_server;
    std::size_t completed = 0;
    std::size_t total = 0;
    std::size_t next = 0;             // индекс следующего непроверенного сервера
    std::atomic<bool> done{false};    // результат получен, новые проверки не нужны
  };
  auto state = std::make_shared<State>();
  state->total = selected_servers.size();

  // Раньше здесь запускалось по потоку на каждый сервер, то есть до десяти
  // одновременных TLS-рукопожатий. На процессоре роутера это давало заметный
  // всплеск при каждом старте клиента. Теперь потоков не больше kMaxConcurrency,
  // и они разбирают серверы по очереди, прекращая работу сразу после результата.
  const std::size_t workers =
      std::min<std::size_t>(kMaxProbeConcurrency, selected_servers.size());
  for (std::size_t i = 0; i < workers; ++i) {
    // NOLINTNEXTLINE(bugprone-exception-escape)
    std::thread([state, selected_servers, sni, timeout_sec,
                    censorship_strategy]() {
      while (!state->done) {
        std::size_t index = 0;
        {
          const std::scoped_lock<std::mutex> lock(state->mtx);  // mutex
          if (state->next >= selected_servers.size()) {
            return;
          }
          index = state->next++;
        }
        const auto& server = selected_servers[index];

        std::uint64_t ms = kMaxTimeout;
        try {
          ms = GetDownloadTimeMs(server, sni, timeout_sec,
              server.md5_fingerprint, censorship_strategy);
        } catch (...) {  // NOLINT
        }
        {
          const std::scoped_lock<std::mutex> lock(state->mtx);  // mutex
          if (ms != kMaxTimeout && !state->first_server.has_value()) {
            state->first_server = server;
            state->done = true;
          }
          ++state->completed;
        }
        state->cv.notify_one();
      }
    }).detach();
  }

  std::unique_lock<std::mutex> lock(state->mtx);
  state->cv.wait_for(lock, std::chrono::seconds(timeout_sec + 2), [&state] {
    return state->first_server.has_value() || state->completed == state->total;
  });

  if (!state->first_server.has_value()) {
    throw std::runtime_error("All servers unavailable!");
  }

  return *state->first_server;
}

std::optional<LoginResult> FindServerByLogin(const std::string& sni,
    const std::vector<ServerInfo>& servers,
    fptn::protocol::https::CensorshipStrategy censorship_strategy,
    int timeout_sec) {
  std::vector<ServerInfo> shuffled_servers = servers;
  std::random_device rd;
  std::mt19937 generator(rd());
  std::ranges::shuffle(shuffled_servers, generator);
  const std::size_t half_size =
      std::max<std::size_t>(1, shuffled_servers.size() / 2);
  std::vector<ServerInfo> selected_servers(
      shuffled_servers.begin(), shuffled_servers.begin() + half_size);

  struct State {
    std::mutex mtx;
    std::condition_variable cv;
    std::optional<LoginResult> result;
    std::size_t completed = 0;
    std::size_t total = 0;
    std::size_t next = 0;             // индекс следующего непроверенного сервера
    std::atomic<bool> done{false};    // результат получен, новые проверки не нужны
  };
  auto state = std::make_shared<State>();
  state->total = selected_servers.size();

  // Ограничение параллелизма, как и в FindFastestServer: каждая попытка входа —
  // это полное TLS-рукопожатие, и на роутере десяток одновременных рукопожатий
  // при каждом запуске службы надолго занимал процессор.
  const std::size_t workers =
      std::min<std::size_t>(kMaxProbeConcurrency, selected_servers.size());
  for (std::size_t i = 0; i < workers; ++i) {
    // NOLINTNEXTLINE(bugprone-exception-escape)
    std::thread([state, selected_servers, sni, timeout_sec,
                    censorship_strategy]() {
      while (!state->done) {
        std::size_t index = 0;
        {
          const std::scoped_lock<std::mutex> lock(state->mtx);  // mutex
          if (state->next >= selected_servers.size()) {
            return;
          }
          index = state->next++;
        }
        const auto& server = selected_servers[index];

        std::optional<LoginResult> local;
        try {
          const std::string body =
              fmt::format(R"({{ "username": "{}", "password": "{}" }})",
                  server.username, server.password);
          ApiClient cli(server.host, server.port, sni, server.md5_fingerprint,
              censorship_strategy);
          const auto resp = cli.Post(
              common::api::kApiLoginUrl, body, "application/json", timeout_sec);
          if (resp.code == 200) {
            const auto msg = resp.Json();
            if (msg.contains("access_token")) {
              local = LoginResult{.server = server,
                  .access_token = msg["access_token"].get<std::string>()};
            }
          }
        } catch (...) {  // NOLINT
        }
        {
          const std::scoped_lock<std::mutex> lock(state->mtx);  // mutex
          if (local && !state->result.has_value()) {
            state->result = std::move(local);
            state->done = true;
          }
          ++state->completed;
        }
        state->cv.notify_one();
      }
    }).detach();
  }

  std::unique_lock<std::mutex> lock(state->mtx);
  state->cv.wait_for(lock, std::chrono::seconds(timeout_sec + 2), [&state] {
    return state->result.has_value() || state->completed == state->total;
  });

  return state->result;
}

}  // namespace fptn::utils::speed_estimator
