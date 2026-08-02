# syntax=docker/dockerfile:1.7

FROM node:24.13-alpine AS frontend-build
WORKDIR /workspace

RUN corepack enable
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
COPY apps/web/package.json apps/web/angular.json apps/web/tsconfig.json apps/web/tsconfig.app.json apps/web/tsconfig.spec.json ./apps/web/
RUN pnpm install --frozen-lockfile

COPY apps/web ./apps/web
RUN pnpm --filter @nexasalud/web build

FROM nginxinc/nginx-unprivileged:1.28-alpine

COPY --from=frontend-build /workspace/apps/web/dist/web/browser /usr/share/nginx/html
COPY apps/api/public /var/www/html/public
COPY infra/production/nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=4s --start-period=15s --retries=5 \
    CMD wget -q -T 3 -O /dev/null http://127.0.0.1:8080/up || exit 1
