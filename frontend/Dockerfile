FROM node:24.22.1-alpine AS build
WORKDIR /app

RUN npm install -g pnpm@12.14.0
ENV PNPM_STRICT_DEP_BUILDS=false

COPY ./package.json ./pnpm-lock.yaml* ./.npmrc ./pnpm-workspace.yaml ./
RUN pnpm install --config.strict-dep-builds=false

COPY ./src ./src
COPY ./angular.json ./tsconfig.json ./tsconfig.app.json ./
RUN pnpm run build-prod

FROM nginx:1.31.7-alpine AS runtime
COPY --from=build /app/dist/flat-designer/browser /usr/share/nginx/html
COPY ./conf/default.conf /etc/nginx/conf.d/default.conf
