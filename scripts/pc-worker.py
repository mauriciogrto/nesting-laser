"""Run NestLaser calculations on this Windows PC for the shared-host site.

Only outbound SFTP is used. The password is read from NEST_FTP_PASS and is never
stored in this script or transmitted in a task. Run with the project's .local
Python environment, which contains Paramiko, Requests and the DXF libraries.
"""

from __future__ import annotations

import base64
import hashlib
import json
import os
import pathlib
import socket
import subprocess
import sys
import tempfile
import time
from typing import Any

import paramiko
import requests


ROOT = pathlib.Path(__file__).resolve().parents[1]
APP = ROOT / "nesting-laser"
ENGINE = APP / "services" / "nesting-engine"
PARSER = APP / "services" / "dxf-parser"
PRIVATE = APP / "storage" / "app" / "private"
REMOTE = os.environ.get("NEST_REMOTE_DIR", "/path/to/app/storage/app/private/engine-bridge")
ENGINE_URL = "http://127.0.0.1:31999"
SCRIPTS = {"extrair_geometria.py", "aplicar_offset.py", "gerar_cnc_saida.py",
           "gerar_dxf_saida.py", "gerar_pdf_arranjo.py"}
HOST_FINGERPRINT = os.environ.get("NEST_HOST_FINGERPRINT", "")  # SHA-256 (base64) da chave do servidor


class PinnedHostKey(paramiko.MissingHostKeyPolicy):
    def missing_host_key(self, client: paramiko.SSHClient, hostname: str,
                         key: paramiko.PKey) -> None:
        fingerprint = base64.b64encode(hashlib.sha256(key.asbytes()).digest()).decode().rstrip("=")
        if key.get_name() != "ssh-ed25519" or fingerprint != HOST_FINGERPRINT:
            raise paramiko.BadHostKeyException(hostname, key, key)
        client.get_host_keys().add(hostname, key.get_name(), key)


def log(message: str) -> None:
    print(time.strftime("%Y-%m-%d %H:%M:%S"), message, flush=True)


def ensure_engine() -> subprocess.Popen[Any] | None:
    for _ in range(3):
        try:
            if requests.get(ENGINE_URL + "/health", timeout=2).status_code == 200:
                return None
        except requests.RequestException:
            pass
        time.sleep(0.5)
    # A busy Node process may delay /health. Do not start a second engine on
    # the same port while the existing process still accepts connections.
    try:
        with socket.create_connection(("127.0.0.1", 31999), timeout=1):
            return None
    except OSError:
        pass
    env = os.environ.copy()
    env["PORT"] = "31999"
    env["HOST"] = "127.0.0.1"
    env["NESTING_PYTHON_BIN"] = sys.executable
    flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
    process = subprocess.Popen(["node", "server.js"], cwd=ENGINE, env=env,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                               creationflags=flags)
    for _ in range(30):
        try:
            if requests.get(ENGINE_URL + "/health", timeout=1).status_code == 200:
                log("Motor local ativo.")
                return process
        except requests.RequestException:
            pass
        if process.poll() is not None:
            raise RuntimeError("O motor Node local encerrou antes de iniciar.")
        time.sleep(0.2)
    raise RuntimeError("O motor Node local não abriu a porta 31999.")


def run_python(payload: dict[str, Any], task_id: str) -> dict[str, Any]:
    script = payload.get("script")
    if script not in SCRIPTS:
        raise ValueError("Operação Python não permitida.")
    input_bytes = base64.b64decode(payload.get("input_base64") or "", validate=True)
    env = os.environ.copy()
    for key, value in {"HOME": PRIVATE, "USERPROFILE": PRIVATE,
                       "XDG_CONFIG_HOME": PRIVATE / "python-config",
                       "XDG_CACHE_HOME": PRIVATE / "python-cache"}.items():
        env[key] = str(value)
    PRIVATE.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="nestlaser-", dir=ROOT / ".local") as temporary:
        temp = pathlib.Path(temporary)
        args: list[str] = []
        artifact = None
        if script == "extrair_geometria.py":
            dxfs = base64.b64decode(payload.get("file_base64") or "", validate=True)
            path = temp / "entrada.dxf"
            path.write_bytes(dxfs)
            args = [str(path)]
        elif script in {"gerar_cnc_saida.py", "gerar_dxf_saida.py"}:
            if payload.get("preview"):
                if script != "gerar_cnc_saida.py":
                    raise ValueError("Preview DXF inválido.")
                args = ["--preview"]
            elif payload.get("save_file"):
                artifact = temp / ("saida.cnc" if script == "gerar_cnc_saida.py" else "saida.dxf")
                args = [str(artifact)]
            else:
                raise ValueError("Destino de programa ausente.")
        process = subprocess.run([sys.executable, str(PARSER / script), *args],
                                 input=input_bytes, capture_output=True, cwd=APP,
                                 env=env, timeout=125, check=False,
                                 creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0)
        result: dict[str, Any] = {"ok": process.returncode == 0,
                                  "output_base64": base64.b64encode(process.stdout).decode("ascii"),
                                  "stderr": process.stderr.decode("utf-8", errors="replace")[:4000]}
        if artifact is not None and process.returncode == 0:
            result["artifact_base64"] = base64.b64encode(artifact.read_bytes()).decode("ascii")
        return result


def calculate(task: dict[str, Any]) -> dict[str, Any]:
    operation = task.get("operacao")
    payload = task.get("payload")
    if not isinstance(payload, dict):
        raise ValueError("Payload de cálculo inválido.")
    if operation == "python":
        return run_python(payload, str(task["id"]))
    if operation not in {"nesting", "avaliar"}:
        raise ValueError("Operação de cálculo não permitida.")
    response = requests.post(ENGINE_URL + "/" + operation, json=payload, timeout=115)
    result = response.json()
    if response.status_code >= 400:
        raise RuntimeError(str(result.get("erro") or "O motor recusou o cálculo."))
    if not isinstance(result, dict):
        raise RuntimeError("Resposta inválida do motor local.")
    return result


def ensure_remote_dir(sftp: paramiko.SFTPClient, path: str) -> None:
    try:
        sftp.stat(path)
    except IOError:
        sftp.mkdir(path)
        sftp.chmod(path, 0o700)


def publish(sftp: paramiko.SFTPClient, path: str, data: bytes) -> None:
    temporary = path + ".tmp"
    with sftp.open(temporary, "wb") as file:
        file.write(data)
    sftp.chmod(temporary, 0o600)
    sftp.posix_rename(temporary, path)


def cleanup_stale(sftp: paramiko.SFTPClient) -> int:
    """Remove abandoned queue files long after Laravel's 180-second limit."""
    cutoff = time.time() - 3600
    removed = 0
    for folder in ("requests", "responses"):
        for entry in sftp.listdir_attr(REMOTE + "/" + folder):
            if not entry.filename.endswith((".json", ".tmp")) or entry.st_mtime >= cutoff:
                continue
            try:
                sftp.remove(REMOTE + "/" + folder + "/" + entry.filename)
                removed += 1
            except FileNotFoundError:
                pass
    return removed


def work_once(sftp: paramiko.SFTPClient, heartbeat) -> None:
    for name in sorted(sftp.listdir(REMOTE + "/requests")):
        if not name.endswith(".json"):
            continue
        heartbeat()
        response_path = REMOTE + "/responses/" + name
        try:
            sftp.stat(response_path)
            continue
        except IOError:
            pass
        request_path = REMOTE + "/requests/" + name
        try:
            request_size = sftp.stat(request_path).st_size
        except FileNotFoundError:
            # PHP removes the request when the browser abandons or times out.
            # This is cancellation, not a broken SFTP connection.
            continue
        if request_size > 50 * 1024 * 1024:
            log("Tarefa acima do limite: " + name)
            continue
        try:
            with sftp.open(request_path, "rb") as file:
                task = json.loads(file.read().decode("utf-8"))
        except FileNotFoundError:
            continue
        if task.get("id") != name[:-5]:
            log("Identificador de tarefa inválido: " + name)
            continue
        log("Processando " + task["operacao"] + " " + name[:8])
        try:
            result = calculate(task)
            output = {"id": task["id"], "ok": True, "result": result}
        except Exception as error:
            output = {"id": task["id"], "ok": False, "erro": str(error)[:1000]}
        try:
            sftp.stat(request_path)
        except FileNotFoundError:
            log("Tarefa cancelada antes da resposta: " + name[:8])
            continue
        publish(sftp, response_path, json.dumps(output, ensure_ascii=False).encode("utf-8"))
        log("Concluído " + name[:8] + (" com erro" if not output["ok"] else ""))
        heartbeat()


def main() -> None:
    password = os.environ.get("NEST_FTP_PASS")
    if not password:
        raise SystemExit("Configure NEST_FTP_PASS no ambiente para iniciar o worker.")
    ensure_engine()
    while True:
        client = None
        try:
            client = paramiko.SSHClient()
            client.set_missing_host_key_policy(PinnedHostKey())
            client.connect(os.environ["NEST_SFTP_HOST"], username=os.environ["NEST_SFTP_USER"], password=password,
                           timeout=15)
            client.get_transport().set_keepalive(15)
            sftp = client.open_sftp()
            for folder in [REMOTE, REMOTE + "/requests", REMOTE + "/responses"]:
                ensure_remote_dir(sftp, folder)
            log("Conectado ao servidor de tarefas.")
            last_heartbeat = 0.0
            last_cleanup = 0.0

            def heartbeat() -> None:
                nonlocal last_heartbeat
                if time.monotonic() - last_heartbeat > 5:
                    publish(sftp, REMOTE + "/heartbeat", str(time.time()).encode("ascii"))
                    last_heartbeat = time.monotonic()

            while True:
                heartbeat()
                if time.monotonic() - last_cleanup > 600:
                    removed = cleanup_stale(sftp)
                    if removed:
                        log("Tarefas temporárias expiradas removidas: " + str(removed))
                    last_cleanup = time.monotonic()
                ensure_engine()
                work_once(sftp, heartbeat)
                time.sleep(0.5)
        except Exception as error:
            log("Conexão ou processamento interrompido; reconectando: "
                + type(error).__name__ + ": " + str(error)[:200])
            time.sleep(2)
        finally:
            if client is not None:
                try:
                    client.close()
                except Exception as error:
                    log("Falha ao fechar a conexão anterior: " + type(error).__name__)


if __name__ == "__main__":
    main()
