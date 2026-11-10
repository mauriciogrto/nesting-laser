"""Focused regression checks for the on-demand PC worker."""

import importlib.util
import json
import pathlib
import unittest
from types import SimpleNamespace
from unittest.mock import MagicMock, patch

import requests


SCRIPT = pathlib.Path(__file__).with_name("pc-worker.py")
SPEC = importlib.util.spec_from_file_location("pc_worker", SCRIPT)
worker = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(worker)


class WorkerHealthTests(unittest.TestCase):
    def test_slow_health_does_not_start_duplicate_engine_when_port_is_open(self):
        with patch.object(worker.requests, "get", side_effect=requests.Timeout), \
                patch.object(worker.socket, "create_connection") as connection, \
                patch.object(worker.subprocess, "Popen") as launch, \
                patch.object(worker.time, "sleep"):
            connection.return_value.__enter__ = MagicMock()
            connection.return_value.__exit__ = MagicMock(return_value=False)

            self.assertIsNone(worker.ensure_engine())

        launch.assert_not_called()

    def test_deleted_request_does_not_break_sftp_session(self):
        sftp = MagicMock()
        sftp.listdir.return_value = ["cancelled.json"]
        sftp.stat.side_effect = FileNotFoundError

        worker.work_once(sftp, lambda: None)

        sftp.open.assert_not_called()

    def test_result_is_discarded_if_request_was_cancelled_during_calculation(self):
        sftp = MagicMock()
        sftp.listdir.return_value = ["cancelled.json"]
        sftp.stat.side_effect = [FileNotFoundError, SimpleNamespace(st_size=100), FileNotFoundError]
        sftp.open.return_value.__enter__.return_value.read.return_value = json.dumps({
            "id": "cancelled", "operacao": "avaliar", "payload": {},
        }).encode()

        with patch.object(worker, "calculate", return_value={"area_utilizada_pct": 0}), \
                patch.object(worker, "publish") as publish:
            worker.work_once(sftp, lambda: None)

        publish.assert_not_called()

    def test_cleanup_removes_only_expired_temporary_queue_files(self):
        sftp = MagicMock()
        sftp.listdir_attr.side_effect = [
            [SimpleNamespace(filename="old.json", st_mtime=1),
             SimpleNamespace(filename="active.json", st_mtime=9_500)],
            [SimpleNamespace(filename="old.json", st_mtime=1),
             SimpleNamespace(filename="heartbeat", st_mtime=1)],
        ]
        with patch.object(worker.time, "time", return_value=10_000):
            removed = worker.cleanup_stale(sftp)

        self.assertEqual(removed, 2)
        self.assertEqual(sftp.remove.call_count, 2)


if __name__ == "__main__":
    unittest.main()
