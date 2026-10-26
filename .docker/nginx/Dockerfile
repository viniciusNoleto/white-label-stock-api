FROM nginx:alpine

RUN apk add --no-cache certbot-nginx
RUN mkdir -p /etc/letsencrypt
RUN rm -f /etc/nginx/conf.d/default.conf
