/*=============================================================================
Copyright (c) 2024-2026 Stas Skokov

Distributed under the MIT License (https://opensource.org/licenses/MIT)
=============================================================================*/

#pragma once

#include <clocale>
#include <cstdlib>
#include <filesystem>
#include <iostream>
#include <memory>
#include <stdexcept>
#include <string>
#include <vector>

#ifdef __ANDROID__
#include <spdlog/sinks/android_sink.h>  // NOLINT(build/include_order)
#elif __APPLE__
#include <TargetConditionals.h>  // NOLINT(build/include_order)
#include <pwd.h>                 // NOLINT(build/include_order)
#include <unistd.h>              // NOLINT(build/include_order)
#elif __linux__
#include <pwd.h>     // NOLINT(build/include_order)
#include <unistd.h>  // NOLINT(build/include_order)
#elif _WIN32
#include <windows.h>
#endif

#include <spdlog/sinks/rotating_file_sink.h>  // NOLINT(build/include_order)
#include <spdlog/sinks/stdout_color_sinks.h>  // NOLINT(build/include_order)
#include <spdlog/spdlog.h>                    // NOLINT(build/include_order)

namespace fptn::logger {
inline bool init(const std::string& app_name) {
  // Set locale
#ifdef _WIN32
  SetConsoleOutputCP(CP_UTF8);
  SetConsoleCP(CP_UTF8);
  std::ios::sync_with_stdio(false);
  std::wcout.imbue(std::locale(".UTF-8"));
#endif
  std::locale::global(std::locale::classic());
#ifndef _WIN32
  ::setenv("LC_ALL", "C", 1);
  ::setenv("LANG", "C", 1);
#endif
  setlocale(LC_ALL, "C");
  try {
#ifdef __ANDROID__
    auto logger = spdlog::android_logger_mt("android", app_name);
#elif TARGET_OS_IPHONE || TARGET_IPHONE_SIMULATOR
    // iOS specific logging - use console only since filesystem access is
    // restricted
    auto console_sink = std::make_shared<spdlog::sinks::stdout_color_sink_mt>();
    auto logger = std::make_shared<spdlog::logger>(app_name, console_sink);
    logger->flush_on(spdlog::level::debug);
    spdlog::flush_every(std::chrono::seconds(3));
#else

#ifdef __linux__
    // Каталог логов переопределяется через FPTN_LOG_DIR. Это нужно встраиваемым
    // системам вроде KeeneticOS, где /var — это tmpfs (ОЗУ) и ротация логов
    // расходовала бы оперативную память роутера вплоть до нехватки памяти.
    const std::filesystem::path log_dir = []() {
      if (const char* dir = getenv("FPTN_LOG_DIR")) {
        if (dir[0] != '\0') {
          return std::filesystem::path(dir);
        }
      }
      return std::filesystem::path("/var/log/fptn/");
    }();
#elif defined(__APPLE__) && TARGET_OS_MAC
    const std::filesystem::path log_dir = []() {
      if (const char* home = getenv("HOME")) {
        return std::filesystem::path(home) / "Library/Logs/fptn";
      }
      struct passwd pwd = {};
      struct passwd* result = nullptr;
      char buffer[1024] = {};
      if (getpwuid_r(getuid(), &pwd, buffer, sizeof(buffer), &result) != 0 ||
          !result) {
        throw std::runtime_error("Failed to get user home directory");
      }
      return std::filesystem::path(pwd.pw_dir) / "Library/Logs/fptn";
    }();
#elif _WIN32
    const std::filesystem::path log_dir = "./logs/";
#endif
    const std::filesystem::path log_file = log_dir / (app_name + ".log");
    if (!std::filesystem::exists(log_dir)) {
      try {
        std::filesystem::create_directories(log_dir);
      } catch (const std::filesystem::filesystem_error& e) {
        std::cerr << "Failed to create log directory: " << e.what() << "\n";
        return false;
      }
    }
    // Размер ротации тоже переопределяется окружением: на роутере разумно
    // держать 1 МБ x 2, тогда как на сервере уместны прежние 12 МБ x 3.
    const auto env_size_t = [](const char* name, std::size_t fallback) {
      if (const char* value = getenv(name)) {
        try {
          const auto parsed = std::stoul(value);
          if (parsed > 0) {
            return static_cast<std::size_t>(parsed);
          }
        } catch (const std::exception&) {  // некорректное значение — берём по умолчанию
        }
      }
      return fallback;
    };
    const std::size_t max_size_mb = env_size_t("FPTN_LOG_MAX_SIZE_MB", 12);
    const std::size_t max_files = env_size_t("FPTN_LOG_MAX_FILES", 3);

    auto console_sink = std::make_shared<spdlog::sinks::stdout_color_sink_mt>();
    auto file_sink = std::make_shared<spdlog::sinks::rotating_file_sink_mt>(
        log_file.string(), max_size_mb * 1024 * 1024, max_files, true);
    auto logger = std::make_shared<spdlog::logger>(
        app_name, spdlog::sinks_init_list{console_sink, file_sink});
    // Принудительный сброс только на ошибках: flush_on(debug) сбрасывал буфер на
    // каждой строке, что на флеш-накопителе роутера ощутимо дорого.
    // Остальное сбрасывается фоновым таймером ниже.
    logger->flush_on(spdlog::level::err);
    spdlog::flush_every(std::chrono::seconds(3));
#endif

    spdlog::set_default_logger(logger);
    spdlog::set_level(spdlog::level::info);
    spdlog::set_pattern("[%Y-%m-%d %H:%M:%S] [%^%l%$] [%s:%#] %v");

#if TARGET_OS_IPHONE || TARGET_IPHONE_SIMULATOR
    SPDLOG_INFO("Logger initialized for iOS - console output only");
#elif __ANDROID__
    SPDLOG_INFO("Logger inited");
#else
    SPDLOG_INFO("Logging to file: {}", log_file.string());
    SPDLOG_INFO("FPTN version: {}", FPTN_VERSION);
#endif
    return true;
  } catch (const spdlog::spdlog_ex& ex) {
#ifdef __ANDROID__
    __android_log_print(ANDROID_LOG_ERROR, "FPTN",
        "Logger initialization failed: %s", ex.what());
#else
    std::cerr << "Logger initialization failed: " << ex.what() << "\n";
#endif
  } catch (...) {
    std::cerr << "Unhandled exception caught in logger\n";
  }
  return false;
}
}  // namespace fptn::logger
