FROM node:24-bookworm-slim

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install

COPY . .
RUN npm run build

RUN mkdir -p /app/data/oauth

EXPOSE 3000

CMD ["npm", "start"]
