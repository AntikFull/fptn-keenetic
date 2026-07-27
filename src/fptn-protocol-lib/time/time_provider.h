/*=============================================================================
Copyright (c) 2024-2026 Stas Skokov

Distributed under the MIT License (https://opensource.org/licenses/MIT)
=============================================================================*/

#pragma once

#include <atomic>
#include <chrono>
#include <cstdint>
#include <ctime>
#include <mutex>
#include <string>
#include <utility>
#include <vector>

namespace fptn::time {

using NtpServers = std::vector<std::pair<std::string, std::uint16_t>>;

class TimeProvider final {
 public:
  static TimeProvider* Instance() {
    static TimeProvider provider;
    return &provider;
  }

  std::string Rfc7231Date();
  std::int32_t OffsetSeconds() const;
  std::uint32_t NowTimestamp();
  bool SyncWithNtp();

 protected:
  explicit TimeProvider(
      NtpServers servers = {
        {"pool.ntp.org", 123},
        {"ru.pool.ntp.org", 123},
        {"ntp.ix.ru", 123}
      });
  bool Refresh();

 private:
  const std::chrono::hours kSyncInterval_{1};
  // Пауза перед повторной попыткой, если синхронизация не удалась.
  const std::chrono::seconds kRetryInterval_{30};

  mutable std::mutex mutex_;
  const NtpServers servers_;

  std::atomic<std::int32_t> offset_seconds_;
  std::atomic<bool> synchronized_{false};
  std::atomic<std::chrono::steady_clock::time_point> last_sync_time_;
};

}  // namespace fptn::time
