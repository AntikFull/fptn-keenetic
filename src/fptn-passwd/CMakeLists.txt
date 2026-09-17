project(fptn-passwd)

include_directories(${CMAKE_CURRENT_SOURCE_DIR})

find_package(Boost REQUIRED)
find_package(OpenSSL REQUIRED)
find_package(argparse REQUIRED)
find_package(Threads REQUIRED)

add_executable("${PROJECT_NAME}" fptn-passwd.cpp)

target_link_libraries("${PROJECT_NAME}" PRIVATE OpenSSL::SSL OpenSSL::Crypto argparse::argparse)

if(UNIX)
  target_link_libraries("${PROJECT_NAME}" PRIVATE Threads::Threads)
  if(NOT APPLE)
    target_link_libraries("${PROJECT_NAME}" PRIVATE atomic)
    target_link_options("${PROJECT_NAME}" PRIVATE "-latomic")
  endif()
endif()
