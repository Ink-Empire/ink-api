#!/bin/bash

# Stop containers
echo "Stopping containers..."
./sail down

# Check for firewall issues (macOS)
echo "Ensuring port 9003 is accessible..."
sudo -n true 2>/dev/null || echo "You might need sudo access to modify firewall rules"

# Create or update xdebug.ini
echo "Updating XDebug configuration..."
cat > ./docker/8.2/xdebug.ini << EOF
[XDebug]
xdebug.mode = develop,debug
xdebug.start_with_request = yes
xdebug.discover_client_host = true
xdebug.client_host = host.docker.internal
xdebug.client_port = 9003
xdebug.idekey = docker
xdebug.log = /var/www/html/storage/logs/xdebug.log
EOF

# Restart containers
echo "Starting containers with new configuration..."
./sail up -d

# Test if XDebug is loaded
echo "Testing XDebug configuration..."
./sail exec laravel.app php -i | grep -i xdebug

echo ""
echo "XDebug setup complete!"
echo "Make sure your IDE is correctly configured:"
echo "1. Debug port set to 9003"
echo "2. Path mappings: local path -> /var/www/html"
echo "3. Start listening for PHP Debug connections in your IDE"
echo ""
echo "Check the XDebug log if issues persist:"
echo "./sail exec laravel.app cat /var/www/html/storage/logs/xdebug.log"