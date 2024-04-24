# Steps to get started

1. Clone repo
2. Composer install
3. Set up local mysql instance and create the db: `create database inkempire;` 
4. once db is created, if you just want the empty tables with no seed data, run `php artisan migrate`
5. if you want seed data, run `php artisan db:seed`. note that this command also drops every table and re-runs migrations, so it will erase any existing data



# Elasticsearch

after running db:seed to get data into your database, ssh into your ink-api container. (`docker compose exec ink-api bash`)

Run the following commands:


`php artisan elastic:create-index "App\\Models\\Tattoo"`

`php artisan elastic:create-index "App\\Models\\Artist"`

`php artisan scout:import "App\\Models\\Tattoo"`

`php artisan scout:import "App\\Models\\Artist"`

You should see that 50 records have been imported. To see your data (locally), you can navigate to

http://127.0.0.1:5601/app/dev_tools#/console

Here is a sample query to check both indexes are created and populated:

```json
GET tattoos/_search
```

```json
GET artists/_search
```
