FROM manticoresearch/manticore-executor:0.4.1-dev

ARG TARGET_ARCH=amd64
ENV MANTICORE_VERSION=5.0.3-230109-8832c302e
ENV EXECUTOR_VERSION=0.5.3-22121910-2bcf464
RUN apt-get -y update && apt-get -y upgrade && \
  apt-get -y install bash figlet mysql-client curl iproute2 && \
  curl -sSL  http://archive.ubuntu.com/ubuntu/pool/main/o/openssl/libssl1.1_1.1.0g-2ubuntu4_amd64.deb > libssl.deb && \
  dpkg -i libssl.deb && rm -f libssl.deb && \
  curl -sSL https://repo.manticoresearch.com/repository/manticoresearch_buster_dev/dists/manticore_${MANTICORE_VERSION}_${TARGET_ARCH}.tgz | tar -xzf - && \
  curl -sSL https://repo.manticoresearch.com/repository/manticoresearch_buster_dev/dists/buster/main/binary-${TARGET_ARCH}/manticore-buddy_0.2.1-23011012-ca4a1d5_all.deb > manticore-buddy.deb && \
  dpkg -i manticore*.deb && rm -f manticore*.deb && \
  mv /usr/bin/manticore-executor /usr/bin/manticore-executor-dev && \
  ln -sf /usr/bin/manticore-executor-dev /usr/bin/php && \
  curl -sSL https://github.com/manticoresoftware/executor/releases/download/v0.5.3/manticore-executor_${EXECUTOR_VERSION}_${TARGET_ARCH}.deb > executor.deb && \
  dpkg -i executor.deb && rm -f executor.deb && \
  apt-get -y autoremove && apt-get -y clean

# alter bash prompt
ENV PS1A="\u@manticore-backup.test:\w> "
RUN echo 'PS1=$PS1A' >> ~/.bashrc && \
  echo 'figlet -w 120 manticore-backup script testing' >> ~/.bashrc

RUN mkdir -p /var/run/manticore && \
  bash -c "mkdir -p /var/{run,log,lib}/manticore-test" && \
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
