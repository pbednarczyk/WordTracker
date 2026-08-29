FROM python:3.12-slim

WORKDIR /srv/nlp

ENV PYTHONDONTWRITEBYTECODE=1
ENV PYTHONUNBUFFERED=1

COPY nlp/requirements.txt ./
RUN python -m pip install --no-cache-dir -r requirements.txt
RUN python -m spacy download en_core_web_sm

COPY nlp ./

CMD ["uvicorn", "wordtracker_nlp.main:app", "--host", "0.0.0.0", "--port", "8000", "--reload"]
