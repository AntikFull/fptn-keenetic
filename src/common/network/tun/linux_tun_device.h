/*=============================================================================
Copyright (c) 2024-2026 Pavel Shpilev

Distributed under the MIT License (https://opensource.org/licenses/MIT)
=============================================================================*/

#pragma once

#include <atomic>
#include <memory>
#include <string>
#include <tuntap++.hh>  // NOLINT(build/include_order)

#include <sys/ioctl.h>
#include <net/if.h>
#include <linux/if_tun.h>
#include <poll.h>
#include <cstring>

namespace fptn::common::network {

class LinuxTunDevice {
 public:
  bool Open(const std::string& name) {
    tun_ = std::make_unique<tuntap::tun>();
    tun_->name(name);
    name_ = tun_->name();
    return true;
  }

  void Close() {
    if (tun_) {
      tun_->nonblocking(true);
      tun_.reset();
    }
  }

  [[nodiscard]] const std::string& GetName() const { return name_; }

  bool ConfigureIPv4(const std::string& addr, int mask) {
    tun_->ip(addr, mask);
    return true;
  }

  bool ConfigureIPv6(const std::string& addr, int mask) {
    tun_->ip(addr, mask);
    return true;
  }

  void SetNonBlocking(bool enabled) { tun_->nonblocking(enabled); }

  void SetMTU(int mtu) { tun_->mtu(mtu); }

  void BringUp() {
    tun_->up();
    int fd = tun_->native_handle();
    if (fd >= 0) {
      int carrier = 1;
      ::ioctl(fd, TUNSETCARRIER, &carrier);
    }
  }

  int Read(void* buffer, int size) {
    return tun_->read(buffer, static_cast<std::size_t>(size));
  }

  int Write(const void* data, int size) {
    return tun_->write(const_cast<void*>(data), static_cast<std::size_t>(size));
  }

  bool WaitForReadable(int timeout_ms) {
    if (!tun_) {
      return false;
    }
    int fd = tun_->native_handle();
    if (fd < 0) {
      return false;
    }
    struct pollfd pfd;
    pfd.fd = fd;
    pfd.events = POLLIN;
    pfd.revents = 0;
    int ret = ::poll(&pfd, 1, timeout_ms);
    return ret > 0 && (pfd.revents & POLLIN);
  }

  // cppcheck-suppress functionStatic
  void SetStopFlag(const std::atomic<bool>* /*running*/) {}

 private:
  std::unique_ptr<tuntap::tun> tun_;
  std::string name_;
};

}  // namespace fptn::common::network
