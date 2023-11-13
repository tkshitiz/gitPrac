FROM node:16-alpine
WORKDIR /app
COPY . .
RUN npm i
CMD ["node", "script.js"]
EXPOSE 3000