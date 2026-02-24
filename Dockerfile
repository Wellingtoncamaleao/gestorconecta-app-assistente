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

# Usuario nao-root para Claude Code CLI (--dangerously-skip-permissions requer nao-root)
RUN useradd -m -s /bin/bash claude-user \
    && usermod -aG www-data claude-user

# Diretorios de trabalho
RUN mkdir -p /var/assistente/fila/processados \
    /var/assistente/sessoes \
    /var/assistente/dedup \
    /var/assistente/logs \
    /workspace \
    /tmp/assistente

# Configs do servidor
COPY nginx.conf /etc/nginx/sites-available/default
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Aplicacao
COPY api/ /var/www/html/api/
COPY prompts/ /var/www/html/prompts/
COPY web/ /var/www/html/web/
COPY worker/ /var/www/worker/

# Instalar dependencias do worker
WORKDIR /var/www/worker
RUN npm install --production

# Permissoes: www-data para PHP, claude-user para worker/CLI
# /var/assistente usa grupo www-data para PHP poder escrever na fila
RUN chown -R www-data:www-data /var/www/html \
    && chown -R claude-user:www-data /var/assistente /workspace /tmp/assistente \
    && chown -R claude-user:claude-user /var/www/worker \
    && chmod -R 775 /var/assistente

WORKDIR /var/www/html
EXPOSE 80

CMD ["/entrypoint.sh"]