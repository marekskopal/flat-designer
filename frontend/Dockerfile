FROM node:24.22.2-alpine AS build
WORKDIR /app

RUN npm install -g pnpm@12.16.1
ENV PNPM_STRICT_DEP_BUILDS=false

COPY ./package.json ./pnpm-lock.yaml* ./.npmrc ./pnpm-workspace.yaml ./
RUN pnpm install --config.strict-dep-builds=false

COPY ./src ./src
COPY ./angular.json ./tsconfig.json ./tsconfig.app.json ./
# The app's path with a trailing slash (`/app/` behind a website at the root).
ARG BASE_HREF=/
RUN pnpm run build-prod --base-href "$BASE_HREF"

FROM nginx:1.31.8-alpine AS runtime
COPY --from=build /app/dist/izbik/browser /usr/share/nginx/html
COPY ./conf/default.conf /etc/nginx/conf.d/default.conf
