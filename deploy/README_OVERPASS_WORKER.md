# Overpass queue worker — deployment notes

This file contains commands and examples to run the Overpass queue worker on a Debian/Ubuntu system.

1) Create queue directory and stats/token files (run as root / with sudo):

```bash
sudo mkdir -p /var/www/html/Allgemein/planvoyage_v2/src/overpass_queue
sudo chown -R www-data:www-data /var/www/html/Allgemein/planvoyage_v2/src/overpass_queue
sudo chmod 2770 /var/www/html/Allgemein/planvoyage_v2/src/overpass_queue

# create initial stats and token-bucket files (worker will update them)
echo '{"total_sent":0,"last_sent_ts":null}' | sudo tee /var/www/html/Allgemein/planvoyage_v2/src/overpass_stats.json > /dev/null
echo '{"tokens":60,"ts":'"$(date +%s)"'}' | sudo tee /var/www/html/Allgemein/planvoyage_v2/src/overpass_token_bucket.json > /dev/null
sudo chown www-data:www-data /var/www/html/Allgemein/planvoyage_v2/src/overpass_stats.json /var/www/html/Allgemein/planvoyage_v2/src/overpass_token_bucket.json
sudo chmod 660 /var/www/html/Allgemein/planvoyage_v2/src/overpass_stats.json /var/www/html/Allgemein/planvoyage_v2/src/overpass_token_bucket.json
```

2) Install the systemd unit (recommended for persistent worker)

```bash
sudo cp deploy/planvoyage-overpass-worker.service /etc/systemd/system/planvoyage-overpass-worker.service
sudo systemctl daemon-reload
sudo systemctl enable --now planvoyage-overpass-worker.service
sudo systemctl status planvoyage-overpass-worker.service
# view logs
sudo journalctl -u planvoyage-overpass-worker.service -f
```

Notes: the unit runs the worker as `www-data` and simply executes the PHP CLI script once and exits; configure Restart/RestartSec as desired. If you prefer a persistent worker loop, consider wrapping the PHP script in a supervising shell script or use a systemd Type=simple service running a small loop.

3) Cron alternative (less preferred): create `/etc/cron.d/planvoyage_overpass` with the following content to run the worker every minute:

```cron
# /etc/cron.d/planvoyage_overpass
# run every minute as www-data
* * * * * www-data /usr/bin/php /var/www/html/Allgemein/planvoyage_v2/src/tools/process_overpass_queue.php >> /var/log/planvoyage_overpass_worker.log 2>&1
```

4) Quick manual test (no sudo if you have access):

```bash
# from the project root
php src/tools/process_overpass_queue.php

# or absolute
php /var/www/html/Allgemein/planvoyage_v2/src/tools/process_overpass_queue.php
```

5) Troubleshooting

- If `curl` to Overpass fails (DNS/timeouts), ensure the server has outgoing DNS and HTTP(S) access. Test with:

```bash
curl -v https://overpass.openstreetmap.org/api/interpreter --data 'data=[out:json];node(50,7,51,8);out;'
```
- Ensure SELinux/AppArmor or a restrictive mount does not block writing to `src/` files.
