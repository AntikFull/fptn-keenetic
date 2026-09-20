cmake_minimum_required(VERSION 3.16)

option(BUILD_TESTING "Build tests" OFF)

set(_LOCAL_LIBTUNTAP_DIR "${CMAKE_CURRENT_LIST_DIR}/../libtuntap")
if(EXISTS "${_LOCAL_LIBTUNTAP_DIR}/CMakeLists.txt")
  set(Libtuntap_SOURCE_DIR "${_LOCAL_LIBTUNTAP_DIR}")
  set(Libtuntap_BINARY_DIR "${CMAKE_BINARY_DIR}/_deps/libtuntap-build")
else()
  include(FetchContent)
  FetchContent_Declare(Libtuntap URL https://github.com/LaKabane/libtuntap/archive/ec1213733eb2e66e033ff8864d9fd476f9e35ffe.zip)
  FetchContent_GetProperties(Libtuntap)
  if(NOT Libtuntap_POPULATED)
    FetchContent_Populate(Libtuntap)
  endif()
  set(Libtuntap_SOURCE_DIR "${CMAKE_BINARY_DIR}/_deps/libtuntap-src")
  set(Libtuntap_BINARY_DIR "${CMAKE_BINARY_DIR}/_deps/libtuntap-build")
endif()

set(Libtuntap_INCLUDE_DIR "${Libtuntap_BINARY_DIR}/include")

set(BUILD_TESTING OFF)
set(LIBTUNTAP_BUILD_TESTS
    OFF
    CACHE BOOL "Disable libtuntap tests")
add_subdirectory("${Libtuntap_SOURCE_DIR}" "${Libtuntap_BINARY_DIR}" EXCLUDE_FROM_ALL)
include_directories("${Libtuntap_SOURCE_DIR}")
include_directories("${Libtuntap_SOURCE_DIR}/bindings/cpp")
include_directories("${Libtuntap_BINARY_DIR}")

link_directories("${Libtuntap_BINARY_DIR}/lib/")
