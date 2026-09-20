/*=============================================================================
Copyright (c) 2024-2026 Stas Skokov

Distributed under the MIT License (https://opensource.org/licenses/MIT)
=============================================================================*/

#include "fptn-client/utils/speed_estimator/speed_estimator.h"

#include <algorithm>
#include <chrono>
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

namespace fptn::utils::speed_estimator {

namespace {

// Безопасная инициализация генератора псевдослучайных чисел
std::mt19937 CreateSafeRng() {
  std::uint32_t seed = static_cast<std::uint32_t>(
      std::chrono::system_clock::now().time_since_epoch().count());
  try {
    std::random_device rd;
    seed ^= rd();
  } catch (...) { // глушение-обосновано: fallback на системное время при сбоях random_device в урезанных окружениях
  }
  return std::mt19937(seed);
}

// Одиночная проверка авторизации без создания потоков
std::optional<LoginResult> TryLoginSingleServer(
    const ServerInfo& server,
    const std::string& sni,
    int timeout_sec,
    fptn::protocol::https::CensorshipStrategy censorship_strategy) {
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
        return LoginResult{.server = server,
            .access_token = msg["access_token"].get<std::string>()};
      }
    }
  } catch (...) { // глушение-обосновано: сервер может быть временно недоступен
  }
  return std::nullopt;
}

}  // namespace

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
  if (servers.empty()) {
    throw std::runtime_error("All servers unavailable: server list is empty!");
  }
  // Перемешиваем серверы для равномерного распределения нагрузки
  std::vector<ServerInfo> shuffled_servers = servers;
  auto generator = CreateSafeRng();
  std::ranges::shuffle(shuffled_servers, generator);
  const std::size_t count =
      std::min<std::size_t>(shuffled_servers.size(), 4);
  std::vector<ServerInfo> selected_servers(
      shuffled_servers.begin(), shuffled_servers.begin() + count);

  struct State {
    std::mutex mtx;
    std::condition_variable cv;
    std::optional<ServerInfo> first_server;
    std::size_t completed = 0;
    std::size_t total = 0;
  };
  auto state = std::make_shared<State>();
  state->total = selected_servers.size();

  std::vector<std::thread> workers;
  workers.reserve(selected_servers.size());
  bool multithread_ok = true;

  try {
    for (const auto& server : selected_servers) {
      workers.emplace_back([state, server, sni, timeout_sec, censorship_strategy]() {
        std::uint64_t ms = kMaxTimeout;
        try {
          ms = GetDownloadTimeMs(server, sni, timeout_sec, server.md5_fingerprint,
              censorship_strategy);
        } catch (...) { // глушение-обосновано: игнорируем ошибку единичного сервера
        }
        {
          const std::scoped_lock<std::mutex> lock(state->mtx);
          if (ms != kMaxTimeout && !state->first_server.has_value()) {
            state->first_server = server;
          }
          ++state->completed;
        }
        state->cv.notify_one();
      });
    }

    {
      std::unique_lock<std::mutex> lock(state->mtx);
      state->cv.wait_for(lock, std::chrono::seconds(timeout_sec + 2), [&state] {
        return state->first_server.has_value() || state->completed == state->total;
      });
    }

    for (auto& w : workers) {
      if (w.joinable()) {
        w.join();
      }
    }
  } catch (const std::exception& ex) {
    SPDLOG_WARN("Multithreaded ping failed ({}), falling back to sequential", ex.what());
    multithread_ok = false;
    for (auto& w : workers) {
      if (w.joinable()) {
        w.join();
      }
    }
  }

  // Последовательный fallback, если многопоточность дала сбой или ни один сервер не ответил
  if (!multithread_ok || !state->first_server.has_value()) {
    for (const auto& server : selected_servers) {
      const auto ms = GetDownloadTimeMs(server, sni, timeout_sec,
          server.md5_fingerprint, censorship_strategy);
      if (ms != kMaxTimeout) {
        return server;
      }
    }
  }

  if (state->first_server.has_value()) {
    return *state->first_server;
  }

  throw std::runtime_error("All servers unavailable!");
}

std::optional<LoginResult> FindServerByLogin(const std::string& sni,
    const std::vector<ServerInfo>& servers,
    fptn::protocol::https::CensorshipStrategy censorship_strategy,
    int timeout_sec) {
  if (servers.empty()) {
    return std::nullopt;
  }
  std::vector<ServerInfo> shuffled_servers = servers;
  auto generator = CreateSafeRng();
  std::ranges::shuffle(shuffled_servers, generator);
  const std::size_t count =
      std::min<std::size_t>(shuffled_servers.size(), 4);
  std::vector<ServerInfo> selected_servers(
      shuffled_servers.begin(), shuffled_servers.begin() + count);

  struct State {
    std::mutex mtx;
    std::condition_variable cv;
    std::optional<LoginResult> result;
    std::size_t completed = 0;
    std::size_t total = 0;
  };
  auto state = std::make_shared<State>();
  state->total = selected_servers.size();

  std::vector<std::thread> workers;
  workers.reserve(selected_servers.size());
  bool multithread_ok = true;

  try {
    for (const auto& server : selected_servers) {
      workers.emplace_back([state, server, sni, timeout_sec, censorship_strategy]() {
        auto local = TryLoginSingleServer(server, sni, timeout_sec, censorship_strategy);
        {
          const std::scoped_lock<std::mutex> lock(state->mtx);
          if (local && !state->result.has_value()) {
            state->result = std::move(local);
          }
          ++state->completed;
        }
        state->cv.notify_one();
      });
    }

    {
      std::unique_lock<std::mutex> lock(state->mtx);
      state->cv.wait_for(lock, std::chrono::seconds(timeout_sec + 2), [&state] {
        return state->result.has_value() || state->completed == state->total;
      });
    }

    for (auto& w : workers) {
      if (w.joinable()) {
        w.join();
      }
    }
  } catch (const std::exception& ex) {
    SPDLOG_WARN("Multithreaded login failed ({}), falling back to sequential", ex.what());
    multithread_ok = false;
    for (auto& w : workers) {
      if (w.joinable()) {
        w.join();
      }
    }
  }

  // Последовательный fallback без потоков
  if (!multithread_ok || !state->result.has_value()) {
    for (const auto& server : selected_servers) {
      auto res = TryLoginSingleServer(server, sni, timeout_sec, censorship_strategy);
      if (res.has_value()) {
        return res;
      }
    }
  }

  return state->result;
}

}  // namespace fptn::utils::speed_estimator
