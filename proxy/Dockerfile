FROM nginx:1.31.7-alpine

COPY ./conf/upstreams.conf /etc/nginx/conf.d/upstreams.conf
COPY ./conf/locations.conf /etc/nginx/snippets/locations.conf
COPY ./conf/http.conf.template /etc/nginx/conf-available/http.conf.template
COPY ./conf/ssl.conf.template /etc/nginx/conf-available/ssl.conf.template
COPY --chmod=0755 ./docker-entrypoint.d/10-select-template.sh /docker-entrypoint.d/10-select-template.sh
