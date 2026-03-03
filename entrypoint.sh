#!/bin/bash
# ASSISTENTE - Entrypoint
# Corrige permissoes dos volumes montados e inicia supervisord

# Volumes montados vem com dono root - corrigir permissoes
# claude-user:www-data para que PHP (www-data) possa escrever na fila
chown -R claude-user:claude-user /home/claude-user/.claude 2>/dev/null
chown -R claude-user:www-data /var/assistente 2>/dev/null
chmod -R 775 /var/assistente

# Garantir que diretorios existem
mkdir -p /var/assistente/fila/processados /var/assistente/dedup /var/assistente/logs /tmp/assistente /workspace
chown -R claude-user:claude-user /tmp/assistente /workspace

# Restaurar .claude.json do backup se nao existir
if [ ! -f /home/claude-user/.claude/.claude.json ]; then
    BACKUP=$(ls -t /home/claude-user/.claude/backups/.claude.json.backup.* 2>/dev/null | head -1)
    if [ -n "$BACKUP" ]; then
        cp "$BACKUP" /home/claude-user/.claude/.claude.json
        chown claude-user:claude-user /home/claude-user/.claude/.claude.json
        echo "[entrypoint] Restaurado .claude.json do backup"
    fi
fi

# Aguardar PostgreSQL estar pronto (Easypanel nao tem depends_on)
if [ -n "$DATABASE_DSN" ]; then
    echo "[entrypoint] Aguardando PostgreSQL..."
    for i in $(seq 1 30); do
        php -r "try { new PDO(getenv('DATABASE_DSN'), getenv('DATABASE_USER'), getenv('DATABASE_PASSWORD')); echo 'OK'; exit(0); } catch(Exception \$e) { exit(1); }" 2>/dev/null && break
        sleep 1
    done
    echo "[entrypoint] PostgreSQL pronto"
fi

echo "[entrypoint] Permissoes corrigidas, iniciando supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
