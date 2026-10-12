FROM node:24.21.0-bookworm-slim

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install --global npm@12.2.0 \
    && npm ci

COPY . .
RUN npm run build

RUN mkdir -p /app/data/oauth \
    && chown -R node:node /app/data

USER node

EXPOSE 3000

CMD ["npm", "start"]
