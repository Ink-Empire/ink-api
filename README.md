# Steps to get started

1. Clone repo
2. Composer install
3. Set up local mysql instance and create the db: `create database inkempire;` 
4. once db is created, if you just want the empty tables with no seed data, run `php artisan migrate`
5. if you want seed data, run `php artisan db:seed`. note that this command also drops every table and re-runs migrations, so it will erase any existing data
