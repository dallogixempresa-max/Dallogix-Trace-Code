FROM python:3.12-alpine@sha256:4c47124a8391cb7a9f571164147d154777cf012a4ece5f86097130d7a4478111

RUN addgroup -S app && adduser -S -G app app
WORKDIR /app
COPY server.py /app/server.py

RUN python -m pip uninstall -y pip

ENV PYTHONUNBUFFERED=1
EXPOSE 1502
USER app
CMD ["python", "/app/server.py"]
