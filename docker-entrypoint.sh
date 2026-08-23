#!/bin/bash

# Start the MQTT subscriber command in the background
echo "Starting MQTT subscriber worker..."
php artisan mqtt:subscribe &

# Execute the default CMD (which is apache2-foreground)
exec "$@"
