cmake_minimum_required(VERSION 3.16)

option(BUILD_TESTING "Build tests" OFF)

include(FetchContent)
FetchContent_Declare(Libtuntap URL https://github.com/LaKabane/libtuntap/archive/ec1213733eb2e66e033ff8864d9fd476f9e35ffe.zip)

FetchContent_GetProperties(Libtuntap)
if(NOT Libtuntap_POPULATED)
  FetchContent_Populate(Libtuntap)
endif()

set(Libtuntap_SOURCE_DIR "${CMAKE_BINARY_DIR}/_deps/libtuntap-src")
set(Libtuntap_BINARY_DIR "${CMAKE_BINARY_DIR}/_deps/libtuntap-build")
set(Libtuntap_INCLUDE_DIR "${Libtuntap_BINARY_DIR}/include")

# Применяем патч для поддержки прямого связывания с интерфейсом KeeneticOS (OpkgTun)
set(_TUNTAP_HH "${Libtuntap_SOURCE_DIR}/bindings/cpp/tuntap++.hh")
if(EXISTS "${_TUNTAP_HH}")
  file(READ "${_TUNTAP_HH}" _TUNTAP_HH_CONTENT)
  if(NOT _TUNTAP_HH_CONTENT MATCHES "explicit tun\\(std::string const &name\\)")
    message(STATUS "[FPTN] Applying Keenetic OpkgTun named interface patch to libtuntap...")

    # Попытка применить патч через git apply или patch утилиту
    set(_PATCH_FILE "${CMAKE_CURRENT_LIST_DIR}/../patches/0003-libtuntap-prestart-name.patch")
    find_program(GIT_EXECUTABLE git)
    find_program(PATCH_EXECUTABLE patch)
    set(_PATCHED FALSE)

    if(GIT_EXECUTABLE AND EXISTS "${_PATCH_FILE}")
      execute_process(
        COMMAND "${GIT_EXECUTABLE}" apply --whitespace=nowarn "${_PATCH_FILE}"
        WORKING_DIRECTORY "${Libtuntap_SOURCE_DIR}"
        RESULT_VARIABLE _GIT_RES
        OUTPUT_QUIET ERROR_QUIET
      )
      if(_GIT_RES EQUAL 0)
        set(_PATCHED TRUE)
        message(STATUS "[FPTN] Successfully applied libtuntap patch via git apply.")
      endif()
    endif()

    if(NOT _PATCHED AND PATCH_EXECUTABLE AND EXISTS "${_PATCH_FILE}")
      execute_process(
        COMMAND "${PATCH_EXECUTABLE}" -p1 -i "${_PATCH_FILE}"
        WORKING_DIRECTORY "${Libtuntap_SOURCE_DIR}"
        RESULT_VARIABLE _PATCH_RES
        OUTPUT_QUIET ERROR_QUIET
      )
      if(_PATCH_RES EQUAL 0)
        set(_PATCHED TRUE)
        message(STATUS "[FPTN] Successfully applied libtuntap patch via patch.")
      endif()
    endif()

    # Если внешних утилит нет, применяем надежную замену строк через CMake
    if(NOT _PATCHED)
      # 1. bindings/cpp/tuntap++.hh
      string(REPLACE "  tun();" "  tun();\n  explicit tun(std::string const &name);" _TUNTAP_HH_CONTENT "${_TUNTAP_HH_CONTENT}")
      file(WRITE "${_TUNTAP_HH}" "${_TUNTAP_HH_CONTENT}")

      # 2. bindings/cpp/tuntap++.cc
      set(_TUNTAP_CC "${Libtuntap_SOURCE_DIR}/bindings/cpp/tuntap++.cc")
      if(EXISTS "${_TUNTAP_CC}")
        file(READ "${_TUNTAP_CC}" _TUNTAP_CC_CONTENT)
        set(_TUNTAP_CC_ADD "tun::tun(std::string const &name)\n    : _dev{tuntap_init()}, _started{true}\n{\n    if (tuntap_set_ifname(_dev, name.c_str()) == -1) {\n        throw std::runtime_error(\"tuntap_set_ifname failed\");\n    }\n\n    if (tuntap_start(_dev, TUNTAP_MODE_TUNNEL, TUNTAP_ID_ANY) == -1) {\n        throw std::runtime_error(\"tuntap_start failed\");\n    }\n}\n\ntun::~tun()")
        string(REPLACE "tun::~tun()" "${_TUNTAP_CC_ADD}" _TUNTAP_CC_CONTENT "${_TUNTAP_CC_CONTENT}")
        file(WRITE "${_TUNTAP_CC}" "${_TUNTAP_CC_CONTENT}")
      endif()

      # 3. tuntap-unix.c
      set(_TUNTAP_UNIX_C "${Libtuntap_SOURCE_DIR}/tuntap-unix.c")
      if(EXISTS "${_TUNTAP_UNIX_C}")
        file(READ "${_TUNTAP_UNIX_C}" _TUNTAP_UNIX_C_CONTENT)
        set(_TUNTAP_UNIX_ADD "    /* Allow the exact interface name to be selected before tuntap_start(). */\n    if (dev->tun_fd == -1) {\n            (void)memset(dev->if_name, 0, IF_NAMESIZE);\n            (void)strncpy(dev->if_name, ifname, len);\n            return 0;\n    }\n\n    if (tuntap_sys_set_ifname(dev, ifname, len) == -1) {")
        string(REPLACE "    if (tuntap_sys_set_ifname(dev, ifname, len) == -1) {" "${_TUNTAP_UNIX_ADD}" _TUNTAP_UNIX_C_CONTENT "${_TUNTAP_UNIX_C_CONTENT}")
        file(WRITE "${_TUNTAP_UNIX_C}" "${_TUNTAP_UNIX_C_CONTENT}")
      endif()

      # 4. tuntap-unix-linux.c
      set(_TUNTAP_LINUX_C "${Libtuntap_SOURCE_DIR}/tuntap-unix-linux.c")
      if(EXISTS "${_TUNTAP_LINUX_C}")
        file(READ "${_TUNTAP_LINUX_C}" _TUNTAP_LINUX_C_CONTENT)
        set(_TUNTAP_LINUX_ADD "    /* Attach to an exact pre-existing TUN such as Keenetic opkgtun1. */\n    if (tun == TUNTAP_ID_ANY && dev->if_name[0] != '\\0') {\n            (void)memset(ifr.ifr_name, 0, sizeof ifr.ifr_name);\n            (void)strncpy(ifr.ifr_name, dev->if_name,\n                sizeof ifr.ifr_name - 1);\n    }\n\n    /* Configure the interface */\n    if (ioctl(fd, TUNSETIFF, &ifr) == -1) {")
        string(REPLACE "    /* Configure the interface */\n    if (ioctl(fd, TUNSETIFF, &ifr) == -1) {" "${_TUNTAP_LINUX_ADD}" _TUNTAP_LINUX_C_CONTENT "${_TUNTAP_LINUX_C_CONTENT}")
        file(WRITE "${_TUNTAP_LINUX_C}" "${_TUNTAP_LINUX_C_CONTENT}")
      endif()
      message(STATUS "[FPTN] Successfully applied libtuntap patch via CMake string manipulation.")
    endif()
  endif()
endif()

set(BUILD_TESTING OFF)
set(LIBTUNTAP_BUILD_TESTS
    OFF
    CACHE BOOL "Disable libtuntap tests")
add_subdirectory("${Libtuntap_SOURCE_DIR}" "${Libtuntap_BINARY_DIR}" EXCLUDE_FROM_ALL)
include_directories("${Libtuntap_SOURCE_DIR}/libtuntap/")
include_directories("${Libtuntap_SOURCE_DIR}/libtuntap/bindings/cpp")

link_directories("${Libtuntap_BINARY_DIR}/lib/")
