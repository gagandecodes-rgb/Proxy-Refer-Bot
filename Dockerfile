# ✅ Dockerfile for your single-file PHP Telegram bot (index.php) + Supabase Postgres
# Works on Render as a Web Service

FROM php:8.2-apache

# Install Postgres PDO driver
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
  && docker-php-ext-install pdo pdo_pgsql \
  && rm -rf /var/lib/apt/lists/*

# (Optional) Enable Apache rewrite (not required, but useful)
RUN a2enmod rewrite

# Copy your project files into Apache web root
COPY . /var/www/html/

# Ensure permissions (safe default)
RUN chown -R www-data:www-data /var/www/html

# Apache listens on 80 by default (Render routes to it)
EXPOSE 80
