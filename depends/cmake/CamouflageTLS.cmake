include(FetchContent)

FetchContent_Declare(CamouflageTLS URL https://github.com/fptn-project/camouflage-tls/archive/refs/heads/main.zip)

FetchContent_GetProperties(CamouflageTLS)
if(NOT camouflagetls_POPULATED)
  FetchContent_Populate(CamouflageTLS)
  # Remove -Werror and set warning as error to OFF
  file(READ "${camouflagetls_SOURCE_DIR}/CMakeLists.txt" CM_CONTENT)
  string(REPLACE "-Werror" "" CM_CONTENT "${CM_CONTENT}")
  string(REPLACE "set(CMAKE_COMPILE_WARNING_AS_ERROR ON)" "set(CMAKE_COMPILE_WARNING_AS_ERROR OFF)" CM_CONTENT "${CM_CONTENT}")
  string(REPLACE "add_subdirectory(tests)" "# add_subdirectory(tests)" CM_CONTENT "${CM_CONTENT}")
  file(WRITE "${camouflagetls_SOURCE_DIR}/CMakeLists.txt" "${CM_CONTENT}")
  
  add_subdirectory(${camouflagetls_SOURCE_DIR} ${camouflagetls_BINARY_DIR})
endif()

set(CamouflageTLS_INCLUDE_DIR "${camouflagetls_SOURCE_DIR}/include")
set(CamouflageTLS_INCLUDE_DIRS ${CamouflageTLS_INCLUDE_DIR} CACHE PATH "Camouflage Include Directories")

if(NOT TARGET CamouflageTLS)
  add_library(CamouflageTLS INTERFACE)
  target_include_directories(CamouflageTLS INTERFACE "${CamouflageTLS_INCLUDE_DIR}")
endif()

target_include_directories(CamouflageTLS INTERFACE "${camouflagetls_SOURCE_DIR}/include")
