#!/usr/bin/env python3
"""Prove check_plugin_layout.py detects what it claims.

A checker nobody has seen fail is decoration. Each test builds a throwaway tree,
runs the real main() against it with --root, and asserts on both the report and
the exit status. The manifests used here are the ones Mahir actually ships
(ai-assistant, signaling-capture), trimmed to the fields that matter.

  python3 scripts/test_check_plugin_layout.py
"""

from __future__ import annotations

import contextlib
import io
import json
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_plugin_layout as layout  # noqa: E402


def ai_assistant_manifest(**overrides):
    data = {
        "slug": "ai-assistant",
        "name": "AI Natural Language PBX Assistant",
        "version": "1.0.0",
        "author": "AiPBX",
        "category": "ai",
        "scopes": ["pbx:read", "pbx:write", "calls:read"],
        "hooks": {"admin_page": {"route": "/assistant", "group": "ai"}},
        "settings_schema": [
            {"key": "api_base_url", "type": "text", "default": "https://api.openai.com/v1"},
            {"key": "api_key", "type": "password", "secret": True, "default": ""},
            {"key": "model", "type": "text", "default": "gpt-4o-mini"},
        ],
    }
    data.update(overrides)
    return data


class Tree:
    """A temp checkout: {relative path: text}."""

    def __init__(self, files: dict[str, str]):
        self.dir = tempfile.mkdtemp(prefix="plugin-layout-")
        for rel, text in files.items():
            path = os.path.join(self.dir, rel)
            os.makedirs(os.path.dirname(path), exist_ok=True)
            with open(path, "w", encoding="utf-8") as fh:
                fh.write(text)

    def __enter__(self):
        return self.dir

    def __exit__(self, *exc):
        import shutil
        shutil.rmtree(self.dir, ignore_errors=True)


def run(root: str, *flags: str) -> tuple[int, str]:
    out = io.StringIO()
    with contextlib.redirect_stdout(out):
        code = layout.main(["--root", root, *flags])
    return code, out.getvalue()


def manifest(data: dict) -> str:
    return json.dumps(data, indent=4)


class MisplacedFiles(unittest.TestCase):
    def test_plugin_file_in_core_path_is_reported(self):
        with Tree({"web/src/services/ai/AssistantModel.php": "<?php\n"}) as root:
            code, out = run(root)
        self.assertEqual(code, 0, "report-only mode must not fail the build")
        self.assertIn("ai-assistant: 1 file(s) still in core paths", out)
        self.assertIn("web/src/services/ai/AssistantModel.php", out)

    def test_strict_mode_fails_on_a_misplaced_file(self):
        with Tree({"web/src/services/ai/AssistantModel.php": "<?php\n"}) as root:
            code, _ = run(root, "--strict")
        self.assertEqual(code, 1)

    def test_a_file_under_its_plugin_directory_is_clean(self):
        with Tree({
            "plugins/ai-assistant/plugin.json": manifest(ai_assistant_manifest()),
            "plugins/ai-assistant/web/src/services/ai/AssistantModel.php": "<?php\n",
        }) as root:
            code, out = run(root, "--strict")
        self.assertEqual(code, 0, out)
        self.assertIn("ok every plugin file lives under plugins/", out)


class Manifests(unittest.TestCase):
    def test_missing_required_field(self):
        data = ai_assistant_manifest()
        del data["scopes"]
        with Tree({"plugins/ai-assistant/plugin.json": manifest(data)}) as root:
            code, out = run(root, "--strict")
        self.assertEqual(code, 1)
        self.assertIn("missing 'scopes'", out)

    def test_slug_must_match_its_folder(self):
        with Tree({"plugins/ai-assistant/plugin.json": manifest(ai_assistant_manifest(slug="assistant"))}) as root:
            code, out = run(root, "--strict")
        self.assertEqual(code, 1)
        self.assertIn("slug is 'assistant'", out)

    def test_admin_page_hook_needs_a_route_or_group(self):
        with Tree({"plugins/ai-assistant/plugin.json": manifest(ai_assistant_manifest(hooks={"admin_page": {}}))}) as root:
            code, out = run(root, "--strict")
        self.assertEqual(code, 1)
        self.assertIn("needs a route or a group", out)

    def test_unknown_hook_is_rejected(self):
        with Tree({"plugins/ai-assistant/plugin.json": manifest(ai_assistant_manifest(hooks={"admin_pages": {}}))}) as root:
            code, out = run(root, "--strict")
        self.assertEqual(code, 1)
        self.assertIn("unknown hook 'admin_pages'", out)

    def test_secret_must_be_a_password_field(self):
        schema = [{"key": "api_key", "type": "text", "secret": True}]
        with Tree({"plugins/ai-assistant/plugin.json": manifest(ai_assistant_manifest(settings_schema=schema))}) as root:
            code, out = run(root, "--strict")
        self.assertEqual(code, 1)
        self.assertIn("secret 'api_key' must be type 'password'", out)

    def test_duplicate_settings_key(self):
        schema = [{"key": "model", "type": "text"}, {"key": "model", "type": "text"}]
        with Tree({"plugins/ai-assistant/plugin.json": manifest(ai_assistant_manifest(settings_schema=schema))}) as root:
            code, out = run(root, "--strict")
        self.assertEqual(code, 1)
        self.assertIn("declared twice", out)

    def test_real_manifest_shape_passes(self):
        with Tree({"plugins/ai-assistant/plugin.json": manifest(ai_assistant_manifest())}) as root:
            code, out = run(root, "--strict")
        self.assertEqual(code, 0, out)
        self.assertNotIn("manifest:", out)


class CodeAgainstManifest(unittest.TestCase):
    """AssistantModel reads api_base_url/api_key/model — the declared three."""

    def code(self, key: str) -> str:
        return (
            "<?php\n"
            "$cfg = PluginManager::getAllSettings('ai-assistant', false);\n"
            f"$apiKey = trim((string)($cfg['{key}'] ?? ''));\n"
        )

    def test_declared_key_is_accepted(self):
        with Tree({
            "plugins/ai-assistant/plugin.json": manifest(ai_assistant_manifest()),
            "plugins/ai-assistant/web/src/services/ai/AssistantModel.php": self.code("api_key"),
        }) as root:
            code, out = run(root, "--strict")
        self.assertEqual(code, 0, out)

    def test_undeclared_key_is_reported(self):
        with Tree({
            "plugins/ai-assistant/plugin.json": manifest(ai_assistant_manifest()),
            "plugins/ai-assistant/web/src/services/ai/AssistantModel.php": self.code("api_token"),
        }) as root:
            code, out = run(root, "--strict")
        self.assertEqual(code, 1)
        self.assertIn("reads settings['api_token']", out)

    def test_chained_read_is_detected(self):
        source = (
            "<?php\n"
            "$m = PluginManager::getAllSettings('signaling-capture')['capture_address'];\n"
        )
        data = ai_assistant_manifest(
            slug="signaling-capture",
            hooks={"sync_domain": "general_dialplan"},
            settings_schema=[{"key": "capture_id", "type": "number"}],
        )
        with Tree({
            "plugins/signaling-capture/plugin.json": manifest(data),
            "plugins/signaling-capture/sync/Sync.php": source,
        }) as root:
            code, out = run(root, "--strict")
        self.assertEqual(code, 1)
        self.assertIn("reads settings['capture_address']", out)


if __name__ == "__main__":
    unittest.main(verbosity=2)
