import os

from conan import ConanFile
from conan.tools.cmake import CMake, CMakeToolchain, cmake_layout
from conan.tools.files import get, replace_in_file


class YaffConan(ConanFile):
    name = "yaff"
    version = "0.0.0"

    settings = "os", "arch", "compiler", "build_type"
    generators = ("CMakeDeps",)

    requires = ("protobuf/5.29.3",)

    default_options = {
        "protobuf/*:lite": True,
        "protobuf/*:upb": False,
        "protobuf/*:with_rtti": False,
        "protobuf/*:with_zlib": False,
        "protobuf/*:debug_suffix": False,
    }

    def layout(self):
        cmake_layout(self)

    def build_requirements(self):
        self.tool_requires("protobuf/5.29.3")

    def source(self):
        get(
            self,
            "https://github.com/fptn-project/yaff/archive/refs/heads/main.zip",
            strip_root=True,
        )
        replace_in_file(
            self,
            os.path.join(self.source_folder, "src", "protoc-plugin", "CMakeLists.txt"),
            "install(TARGETS yaff_protoc_plugin\n    EXPORT YaFFTargets\n",
            "install(TARGETS yaff_protoc_plugin\n",
        )
        # MSVC only forward-declares std::ostream via <string_view>, so the
        # operator<< in array.h fails with an incomplete type. Pull in <ostream>.
        replace_in_file(
            self,
            os.path.join(self.source_folder, "include", "yaff", "array.h"),
            "#include <string_view>",
            "#include <ostream>\n#include <string_view>",
        )
        replace_in_file(
            self,
            os.path.join(self.source_folder, "src", "compilation", "error.h"),
            "#include <string>",
            "#include <cstdint>\n#include <string>",
        )
        # Fix missing std::bit_cast on GCC 11 MIPS cross-compiler
        base_h = os.path.join(self.source_folder, "include", "yaff", "base.h")
        replace_in_file(
            self,
            base_h,
            "return std::bit_cast<float>(std::bit_cast<uint32_t>(v) ^ std::bit_cast<uint32_t>(d));",
            "uint32_t vi, di; std::memcpy(&vi, &v, 4); std::memcpy(&di, &d, 4); vi ^= di; float res; std::memcpy(&res, &vi, 4); return res;",
        )
        replace_in_file(
            self,
            base_h,
            "return std::bit_cast<double>(std::bit_cast<uint64_t>(v) ^ std::bit_cast<uint64_t>(d));",
            "uint64_t vi, di; std::memcpy(&vi, &v, 8); std::memcpy(&di, &d, 8); vi ^= di; double res; std::memcpy(&res, &vi, 8); return res;",
        )
        replace_in_file(self, base_h, "inline constexpr float XorDef(float", "inline float XorDef(float")
        replace_in_file(self, base_h, "inline constexpr double XorDef(double", "inline double XorDef(double")
        # Fix missing std::ostringstream::view() on GCC 11
        protoc_plugin_cpp = os.path.join(self.source_folder, "src", "protoc-plugin", "protoc_plugin.cpp")
        replace_in_file(self, protoc_plugin_cpp, "headerOutput.view()", "headerOutput.str()")
        replace_in_file(self, protoc_plugin_cpp, "sourceOutput.view()", "sourceOutput.str()")

    def generate(self):
        tc = CMakeToolchain(self)
        tc.variables["YAFF_BUILD_TESTS"] = False
        tc.variables["YAFF_BUILD_BENCHMARKS"] = False
        tc.variables["YAFF_BUILD_EXAMPLES"] = False
        tc.generate()

    def build(self):
        cmake = CMake(self)
        cmake.configure()
        cmake.build()

    def package(self):
        cmake = CMake(self)
        cmake.install()

    def package_info(self):
        self.cpp_info.set_property("cmake_find_mode", "none")
        self.cpp_info.builddirs = ["lib/cmake/YaFF"]
        self.cpp_info.bindirs = ["bin"]
