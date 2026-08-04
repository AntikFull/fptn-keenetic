#pragma once

/*=============================================================================
Copyright (c) 2024-2026 Stas Skokov

Distributed under the MIT License (https://opensource.org/licenses/MIT)
=============================================================================*/

#include <boost/asio.hpp>
#include <boost/process.hpp>

#include "vpn/vpn_manager.h"

namespace fptn::utils {

bool WaitForSignal(fptn::vpn::VpnManager& vpn_client) {
  boost::asio::io_context io_context;
  boost::asio::signal_set signals(io_context, SIGINT, SIGTERM);
  bool stopped_by_signal = false;

  boost::asio::steady_timer check_timer(io_context);

  std::function<void()> check_connection = [&]() {
    if (vpn_client.HasGivenUp()) {
      SPDLOG_ERROR("VPN supervisor исчерпал лимит полных перезапусков");
      io_context.stop();
      return;
    }

    check_timer.expires_after(std::chrono::seconds(1));
    check_timer.async_wait([&](const boost::system::error_code& ec) {
      if (!ec) {
        check_connection();
      }
    });
  };

  signals.async_wait([&](auto, auto) {
    stopped_by_signal = true;
    io_context.stop();
  });
  check_timer.expires_after(std::chrono::seconds(1));
  check_timer.async_wait([&](const boost::system::error_code& ec) {
    if (!ec) {
      check_connection();
    }
  });
  io_context.run();
  return stopped_by_signal;
}
}  // namespace fptn::utils
