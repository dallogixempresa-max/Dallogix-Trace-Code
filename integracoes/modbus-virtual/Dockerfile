FROM python:3.12-alpine

RUN addgroup -S app && adduser -S -G app app
WORKDIR /app
COPY server.py /app/server.py

RUN python -m pip uninstall -y pip

ENV PYTHONUNBUFFERED=1
EXPOSE 1502
USER app
CMD ["python", "/app/server.py"]
