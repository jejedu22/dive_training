FROM node:22-alpine AS dev
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
CMD ["npm", "run", "dev", "--", "--host"]

FROM dev AS build
RUN npm run build

FROM nginx:alpine
COPY --from=build /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf
