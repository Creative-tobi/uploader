FROM php:8.2-apache

# Install dependencies for file uploads and APIs
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Copy your code into the container
COPY . /var/www/html/

# Create the uploads folder and set permissions
RUN mkdir -p /var/www/html/uploads && \
    chmod -R 777 /var/www/html/uploads

# Set the Apache port
EXPOSE 80