#!/bin/bash

# Define the port you want to use for XDebug
XDEBUG_PORT=9003

echo "Fixing XDebug configuration in your Laravel Sail environment..."

# 1. Update the .env file
echo "Updating .env file..."
grep -q "SAIL_XDEBUG_CONFIG=" .env
if [ $? -eq 0 ]; then
    # Update existing entry
    sed -i '' "s/SAIL_XDEBUG_CONFIG=.*/SAIL_XDEBUG_CONFIG=\"client_host=host.docker.internal client_port=$XDEBUG_PORT idekey=docker\"/" .env
else
    # Add new entry
    echo "SAIL_XDEBUG_CONFIG=\"client_host=host.docker.internal client_port=$XDEBUG_PORT idekey=docker\"" >> .env
fi

# 2. Update the xdebug.ini file
echo "Updating xdebug.ini file..."
cat > docker/8.2/xdebug.ini << EOF
[XDebug]
zend_extension=xdebug
xdebug.mode = develop,debug,coverage
xdebug.start_with_request = yes
xdebug.discover_client_host = true
xdebug.client_host = host.docker.internal
xdebug.client_port = $XDEBUG_PORT
xdebug.idekey = docker
xdebug.log = /var/www/html/storage/logs/xdebug.log
EOF

# 3. Rebuild the sail container
echo "Stopping and rebuilding containers..."
./sail down
./sail up -d --build

# 4. Inject the xdebug settings directly into the container
echo "Ensuring correct XDebug configuration in running container..."
./sail exec --user=root laravel.app bash -c "echo 'xdebug.client_port=$XDEBUG_PORT' > /usr/local/etc/php/conf.d/99-xdebug-port.ini"
./sail exec --user=root laravel.app bash -c "php -m | grep -i xdebug"

echo ""
echo "XDebug should now be properly configured with port $XDEBUG_PORT."
echo "To verify, run: ./sail exec laravel.app php -i | grep xdebug.client_port"
echo ""
echo "Make sure your IDE is configured to listen on port $XDEBUG_PORT."
echo "Common IDE configurations:"
echo "  - PhpStorm: Go to Settings > PHP > Debug > XDebug > Debug port: $XDEBUG_PORT"
echo "  - VS Code: Update 'launch.json' with port: $XDEBUG_PORT"
echo ""