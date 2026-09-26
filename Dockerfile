# Imaginea pentru inspectoarele și cataloagele MCP (de exemplu Glama): pornește un site demo gol, local,
# și îl expune prin stdio (unelte/stdio.php). Nu e imaginea de producție: site-ul real stă pe găzduire,
# iar AI-ul se leagă la https://site/mcp.
FROM php:8.3-cli-alpine
WORKDIR /app
COPY site ./site
COPY unelte/stdio.php unelte/router-local.php ./unelte/
ENTRYPOINT ["php", "unelte/stdio.php"]
