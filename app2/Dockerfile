FROM python:3.11-slim

# Install dependencies
RUN pip install --no-cache-dir flask requests gunicorn

# Copy source code
COPY ./src/ /app/
WORKDIR /app

# Security: run as non-root user
RUN adduser --disabled-password --gecos "" appuser && \
    chown -R appuser:appuser /app
USER appuser

# Run with Gunicorn production server (bukan Flask dev server)
CMD ["gunicorn", "--bind", "0.0.0.0:5000", "app:app"]