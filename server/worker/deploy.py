#!/usr/bin/env python3
"""Run the AM installer with the running worker's own Python environment."""
import os
from pathlib import Path
import subprocess
import sys
from install import matching_service


def main():
    if os.geteuid() != 0:
        raise RuntimeError('Run as root on the auth-worker host.')
    directory = Path('/opt/iptv-auth-worker')
    service = matching_service(directory)
    pid = subprocess.check_output(['systemctl','show',service,'--property=MainPID','--value'],text=True).strip()
    if not pid.isdigit() or int(pid) <= 0:
        raise RuntimeError('Running worker PID unavailable.')
    command = Path('/proc',pid,'cmdline').read_bytes().split(b'\0')
    python = Path(os.fsdecode(command[0]))
    # The Python shebang is reflected as argv[0] for uvicorn console scripts.
    if not python.is_absolute() or not python.name.startswith('python') or not python.is_file():
        raise RuntimeError('Could not identify the worker Python interpreter. Report the systemd service name: '+service)
    print('Using running worker environment: '+str(python),flush=True)
    subprocess.run([str(python),str(Path(__file__).with_name('install.py')),'--worker',str(directory/'main.py'),'--apply','--restart'],check=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        sys.exit('AM deployment stopped: '+str(error))
