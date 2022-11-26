FROM manticoresearch/manticore-executor:0.3.5-dev

ARG TARGET_ARCH=amd64
ENV MANTICORE_VERSION=5.0.3-221020-cd2335eec
RUN apt-get -y update && apt-get -y upgrade && \
  curl -sSL  http://archive.ubuntu.com/ubuntu/pool/main/o/openssl/libssl1.1_1.1.0g-2ubuntu4_amd64.deb > libssl.deb && \
  dpkg -i libssl.deb && rm -f libssl.deb && \
  curl -sSL https://repo.manticoresearch.com/repository/manticoresearch_buster_dev/dists/manticore_${MANTICORE_VERSION}_${TARGET_ARCH}.tgz | tar -xzf - && \
  dpkg -i manticore*.deb && \
  apt-get -y autoremove && apt-get -y clean

# alter bash prompt
ENV PS1A="\u@manticore-backup.test:\w> "
RUN echo 'PS1=$PS1A' >> ~/.bashrc && \
  echo 'figlet -w 120 manticore-backup script testing' >> ~/.bashrc

RUN mkdir -p /var/run/manticore/ && \
  mkdir -p /usr/share/manticore/morph/ && \
  echo -e 'a\nb\nc\nd\n' > /usr/share/manticore/morph/test

RUN echo "common { \n\
    plugin_dir = /usr/local/lib/manticore\n\
    lemmatizer_base = /usr/share/manticore/morph/\n\
}\n\
searchd {\n\
    listen = 0.0.0:9312\n\
    listen = 0.0.0:9306:mysql\n\
    listen = 0.0.0:9308:http\n\
    log = /var/log/manticore/searchd.log\n\
    query_log = /var/log/manticore/query.log\n\
    pid_file = /var/run/manticore/searchd.pid\n\
    data_dir = /var/lib/manticore\n\
    query_log_format = sphinxql\n\
}\n" > "/etc/manticoresearch/manticore.conf"

# Prevent the container from exiting
ENTRYPOINT ["tail"]
CMD ["-f", "/dev/null"]
