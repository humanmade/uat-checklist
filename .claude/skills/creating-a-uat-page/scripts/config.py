"""Where the site is and how to reach it, for the Python scripts. lib.mjs reads the same variables for Node.

UAT_URL       The site's home URL, required. A network site's own URL, path included.
UAT_WP        The command that runs WP-CLI for that site, split on spaces. Default `wp`. For example
              `ddev wp`, `lando wp`, `npx wp-env run cli wp`, or `vip dev-env exec --slug <slug> --quiet -- wp`.
              It must pass stdin through.
UAT_HUB_SLUG  Slug of the top-level hub page pages are built under. Default `hub`; empty for none.
UAT_CACHE     Where screenshots and the test users' passwords are kept. Default ~/.cache/hm-uat/<site>.
"""

import os
import re
import shlex
import sys
from pathlib import Path
from urllib.parse import urlparse

if not os.environ.get('UAT_URL'):
    sys.exit('Set UAT_URL to the site\'s home URL, for example UAT_URL=http://localhost:8888/')

UAT_URL = os.environ['UAT_URL'].rstrip('/') + '/'
SITE_PATH = urlparse(UAT_URL).path.rstrip('/')
HUB_SLUG = os.environ.get('UAT_HUB_SLUG', 'hub')
SITE_KEY = re.sub(r'[^a-z0-9]+', '-', (urlparse(UAT_URL).netloc + SITE_PATH).lower()).strip('-')
CACHE = Path(os.environ.get('UAT_CACHE') or Path.home() / '.cache' / 'hm-uat' / SITE_KEY)


def wp_command():
    """The WP-CLI command for the site, with --url set."""
    return [*shlex.split(os.environ.get('UAT_WP', 'wp')), f'--url={UAT_URL}']
