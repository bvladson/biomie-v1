import sys
import paramiko
import logging

# Enable verbose logging to stdout
logging.basicConfig(level=logging.DEBUG)

def run_ssh_command(cmd):
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        print("Connecting to SSH server...")
        ssh.connect('82.112.247.199', port=65002, username='u106688109', password='Mortal@mag6135', timeout=10, auth_timeout=10)
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
