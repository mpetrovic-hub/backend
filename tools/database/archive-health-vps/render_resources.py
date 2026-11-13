#!/usr/bin/python3
"""Print a reviewed service drop-in from the shared configuration; never install it."""
import sys
from kiwi_retention_archive_health import HealthError, load_configuration


def main():
    try:
        if len(sys.argv) != 1:
            raise HealthError('Resource rendering takes no arguments.')
        limits = load_configuration().limits
        print('[Service]')
        print(f'CPUQuota={limits.cpu_quota_percent}%')
        print(f'CPUWeight={limits.cpu_weight}')
        print('IOReadBandwidthMax=')
        print(f'IOReadBandwidthMax=/var/lib/kiwi-archive-health {limits.read_bytes_per_second}')
        print('IOWriteBandwidthMax=')
        print(f'IOWriteBandwidthMax=/var/lib/kiwi-archive-health {limits.write_bytes_per_second}')
        return 0
    except (HealthError, OSError, ValueError):
        print('Shared resource configuration could not be validated.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
