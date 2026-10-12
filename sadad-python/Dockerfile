FROM python:3.13-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app ./app
COPY wsgi.py .
RUN useradd --system --uid 10001 appuser && mkdir -p /app/data && chown -R appuser /app/data
USER appuser
ENV DATA_DIR=/app/data
EXPOSE 8000
CMD ["gunicorn", "--preload", "--workers", "3", "--bind", "0.0.0.0:8000", "--access-logfile", "-", "wsgi:app"]
