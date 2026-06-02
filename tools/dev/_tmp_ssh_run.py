import sys
import os
import getpass
import paramiko

DEFAULT_HOST = '82.112.247.199'
DEFAULT_PORT = 65002
DEFAULT_USER = 'u106688109'

def run_ssh_command(cmd):
    host = os.environ.get('BIOMIE_SSH_HOST', DEFAULT_HOST)
    port = int(os.environ.get('BIOMIE_SSH_PORT', str(DEFAULT_PORT)))
    username = os.environ.get('BIOMIE_SSH_USER', DEFAULT_USER)
    password = os.environ.get('BIOMIE_SSH_PASSWORD') or getpass.getpass('SSH password: ')

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        print("Connecting to SSH server...")
        ssh.connect(host, port=port, username=username, password=password, timeout=10, auth_timeout=10)
        print("Connected! Executing command...")
        stdin, stdout, stderr = ssh.exec_command(cmd, timeout=300)
        print("Command executed. Reading stdout...")
        out = stdout.read().decode('utf-8', errors='ignore')
        print("Reading stderr...")
        err = stderr.read().decode('utf-8', errors='ignore')
        print(f"=== STDOUT ===\n{out}")
        if err:
            print(f"=== STDERR ===\n{err}")
        return 0
    except Exception as e:
        print(f"SSH Exception: {e}")
        return 1
    finally:
        ssh.close()

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage: python _tmp_ssh_run.py '<command>'")
        sys.exit(1)
    cmd = sys.argv[1]
    print(f"Executing: {cmd}")
    sys.exit(run_ssh_command(cmd))
