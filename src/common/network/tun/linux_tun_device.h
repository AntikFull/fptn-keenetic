/*=============================================================================
Copyright (c) 2024-2026 Pavel Shpilev

Distributed under the MIT License (https://opensource.org/licenses/MIT)
=============================================================================*/

#pragma once

#include <atomic>
#include <memory>
#include <string>
#include <tuntap++.hh>  // NOLINT(build/include_order)

#if defined(__linux__)
#include <arpa/inet.h>
#include <net/if.h>
#include <netinet/in.h>
#include <sys/ioctl.h>
#include <sys/socket.h>
#include <unistd.h>
#include <cstring>
#ifndef TUNSETCARRIER
#define TUNSETCARRIER _IOW('T', 226, int)
#endif
#endif

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

  [[nodiscard]] std::string GetActualIPv4Address() const {
#if defined(__linux__)
    if (name_.empty()) {
      return "";
    }
    int fd = socket(AF_INET, SOCK_DGRAM, 0);
    if (fd < 0) {
      return "";
    }
    struct ifreq ifr;
    std::memset(&ifr, 0, sizeof(ifr));
    std::strncpy(ifr.ifr_name, name_.c_str(), IFNAMSIZ - 1);
    if (ioctl(fd, SIOCGIFADDR, &ifr) >= 0) {
      struct sockaddr_in ipaddr;
      std::memcpy(&ipaddr, &ifr.ifr_addr, sizeof(ipaddr));
      char ip_str[INET_ADDRSTRLEN] = {0};
      if (inet_ntop(AF_INET, &ipaddr.sin_addr, ip_str, sizeof(ip_str))) {
        close(fd);
        return std::string(ip_str);
      }
    }
    close(fd);
#endif
    return "";
  }

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
#if defined(__linux__)
    if (tun_) {
      int carrier = 1;
      ioctl(tun_->native_handle(), TUNSETCARRIER, &carrier);
    }
#endif
  }

  int Read(void* buffer, int size) {
    return tun_->read(buffer, static_cast<std::size_t>(size));
  }

  int Write(const void* data, int size) {
    return tun_->write(const_cast<void*>(data), static_cast<std::size_t>(size));
  }

  // cppcheck-suppress functionStatic
  void SetStopFlag(const std::atomic<bool>* /*running*/) {}

 private:
  std::unique_ptr<tuntap::tun> tun_;
  std::string name_;
};

}  // namespace fptn::common::network
