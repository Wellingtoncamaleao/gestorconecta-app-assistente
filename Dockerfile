FROM php:8.4-fpm-bookworm

# Dependencias do sistema
RUN apt-get update && apt-get install -y \
    nginx supervisor curl git jq \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# Node.js 22
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Claude Code CLI
RUN npm install -g @anthropic-ai/claude-code

# Diretorios de trabalho
RUN mkdir -p /var/assistente/fila/processados \
    /var/assistente/sessoes \
    /var/assistente/logs

# Configs do servidor
COPY nginx.conf /etc/nginx/sites-available/default
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Aplicacao
COPY api/ /var/www/html/api/
COPY prompts/ /var/www/html/prompts/
COPY web/ /var/www/html/web/
COPY worker/ /var/www/worker/

# Instalar dependencias do worker
WORKDIR /var/www/worker
RUN npm install --production

# Permissoes
RUN chown -R www-data:www-data /var/www/html /var/assistente

WORKDIR /var/www/html
EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]