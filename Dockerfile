FROM php:8.4-fpm-bookworm

# Dependencias do sistema + GD + ffmpeg + python3 + chromium
RUN apt-get update && apt-get install -y \
    nginx supervisor curl git jq procps \
    libcurl4-openssl-dev libpq-dev \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    ffmpeg python3 python3-pip python3-venv \
    chromium \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install curl gd pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# yt-dlp (download de midia Instagram/YouTube)
RUN pip3 install --break-system-packages yt-dlp

# Node.js 22
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Claude Code CLI
RUN npm install -g @anthropic-ai/claude-code

# GitHub CLI (para push/PR sem precisar de SSH key)
RUN curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg \
    | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" \
    > /etc/apt/sources.list.d/github-cli.list \
    && apt-get update && apt-get install -y gh \
    && rm -rf /var/lib/apt/lists/*

# Usuario nao-root para Claude Code CLI (--dangerously-skip-permissions requer nao-root)
RUN useradd -m -s /bin/bash claude-user \
    && usermod -aG www-data claude-user \
    && git config --system user.name "Well-dev Assistente" \
    && git config --system user.email "we@we.com"

# Diretorios de trabalho
RUN mkdir -p /var/assistente/fila/processados \
    /var/assistente/sessoes \
    /var/assistente/dedup \
    /var/assistente/logs \
    /workspace \
    /tmp/assistente \
    /tmp/assistente/media

# Configs do servidor
COPY nginx.conf /etc/nginx/sites-available/default
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Aplicacao
COPY api/ /var/www/html/api/
COPY prompts/ /var/www/html/prompts/
COPY tools/ /var/www/html/tools/
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