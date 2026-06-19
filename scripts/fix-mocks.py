#!/usr/bin/env python3
"""One-off maintenance script: repair tests/mock-functions.php so php -l passes.

It lexes the file with proper string/comment awareness, extracts every bracketed
namespace block (named or empty), then reassembles the file with all named
namespaces moved to the top and the original global code wrapped in a single
anonymous namespace block. Idempotent.
"""
import os
import re
import sys


def fix_mock_functions(filepath: str) -> None:
    """Lex, extract namespaces, reassemble."""
    print(f"Step 1: Reading {filepath}...")
    try:
        with open(filepath, "r", encoding="utf-8") as f:
            content = f.read()
        print(" -> PASS")
    except Exception as e:
        print(f" -> FAIL: {e}")
        return

    content = re.sub(r"^\s*<\?php\s*", "", content)

    print("Step 2: Lexing and extracting namespaces...")

    namespaces = []
    global_code_chunks = []
    length = len(content)
    i = 0
    in_string = False
    string_char = ""
    in_sline_comment = False
    in_mline_comment = False
    current_chunk = []
    brace_depth = 0
    capturing_namespace = False

    while i < length:
        if in_string and content[i] == "\\":
            current_chunk.append(content[i : i + 2])
            i += 2
            continue

        if not in_sline_comment and not in_mline_comment:
            if content[i] in ('"', "'"):
                if not in_string:
                    in_string = True
                    string_char = content[i]
                elif string_char == content[i]:
                    in_string = False

        if not in_string:
            if not in_mline_comment and not in_sline_comment and content[i : i + 2] == "//":
                in_sline_comment = True
            elif in_sline_comment and content[i] == "\n":
                in_sline_comment = False
            elif not in_sline_comment and not in_mline_comment and content[i : i + 2] == "/*":
                in_mline_comment = True
            elif in_mline_comment and content[i : i + 2] == "*/":
                in_mline_comment = False
                current_chunk.append("*/")
                i += 2
                continue

        if not in_string and not in_sline_comment and not in_mline_comment:
            if brace_depth == 0 and not capturing_namespace:
                match = re.match(
                    r"^namespace(?:\s+[a-zA-Z0-9_\\]+)?\s*\{",
                    content[i:],
                )
                if match:
                    if current_chunk:
                        global_code_chunks.append("".join(current_chunk))
                        current_chunk = []
                    capturing_namespace = True
                    brace_depth = 1
                    current_chunk.append(match.group(0))
                    i += len(match.group(0))
                    continue

            if content[i] == "{":
                brace_depth += 1
            elif content[i] == "}":
                brace_depth -= 1
                if capturing_namespace and brace_depth == 0:
                    current_chunk.append("}")
                    namespaces.append("".join(current_chunk))
                    current_chunk = []
                    capturing_namespace = False
                    i += 1
                    continue

        current_chunk.append(content[i])
        i += 1

    if current_chunk:
        if capturing_namespace:
            print(" -> FAIL: Unclosed namespace block detected.")
            return
        global_code_chunks.append("".join(current_chunk))

    print(" -> PASS")

    print("Step 3: Reassembling file...")
    global_code_cleaned = "".join(global_code_chunks).strip()

    out = "<?php\n\n"
    named_namespaces = [
        ns
        for ns in namespaces
        if re.match(r"^namespace\s+[a-zA-Z0-9_\\]+\s*\{", ns.strip())
    ]
    for ns in named_namespaces:
        out += ns.strip() + "\n\n"

    out += "namespace {\n"

    anon_namespaces = [
        ns for ns in namespaces if re.match(r"^namespace\s*\{", ns.strip())
    ]
    for ns in anon_namespaces:
        inner = re.sub(r"^namespace\s*\{\s*", "", ns.strip())
        inner = re.sub(r"\}\s*$", "", inner)
        out += inner + "\n"

    out += global_code_cleaned + "\n}\n"
    print(" -> PASS")

    print("Step 4: Writing updated file...")
    try:
        with open(filepath, "w", encoding="utf-8") as f:
            f.write(out)
        print(" -> PASS")
    except Exception as e:
        print(f" -> FAIL: {e}")


if __name__ == "__main__":
    target = (
        sys.argv[1]
        if len(sys.argv) > 1
        else "wp-content/plugins/novamira-adrianv2/tests/mock-functions.php"
    )
    fix_mock_functions(target)
