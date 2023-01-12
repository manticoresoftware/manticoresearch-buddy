FROM manticoresearch/manticore-executor:0.6.1-dev

ARG TARGET_ARCH=amd64
ENV MANTICORE_REV=9dcd3f47d12d8c40e20db030d2f2ded6ba57a795
ENV COLUMNAR_REV=2ca756ce46520d514022d4d145009e362ba9cb74
ENV EXECUTOR_VERSION=0.6.1-230116-424b1dc

# Build manticore and columnar first
ENV BUILD_DEPS="curl autoconf automake cmake alpine-sdk openssl-dev bison flex git boost-static boost-dev"
RUN apk update && apk add gcc $BUILD_DEPS && \
  git clone https://github.com/manticoresoftware/columnar.git && \
    cd columnar && \
    git checkout $COLUMNAR_REV && \
    mkdir build && cd build && \
    cmake -DCMAKE_BUILD_TYPE=Release -DWITH_GALERA=0 -DBUILD_TESTING=OFF .. && \
    make -j8 && make install && cd .. && rm -fr columnar && \
  git clone https://github.com/manticoresoftware/manticoresearch.git && \
    cd manticoresearch && \
    git checkout $MANTICORE_REV && \
    mkdir build && cd build && \
    cmake -DCMAKE_BUILD_TYPE=Release -DWITH_GALERA=0 -DBUILD_TESTING=OFF .. && \
    make -j8 && make install && cd .. && rm -fr manticoresearch && \
  apk del $BUILD_DEPS && \
  rm -fr /var/cache/apk/* && \
  cp /usr/local/etc/manticoresearch/manticore.conf /etc/manticore.conf

# Get production version and keep dev for executor
RUN apk update && \
  apk add bash figlet mysql-client curl iproute2 apache2-utils && \
  mv /usr/bin/manticore-executor /usr/bin/manticore-executor-dev && \
  ln -sf /usr/bin/manticore-executor-dev /usr/bin/php && \
  curl -sSL https://github.com/manticoresoftware/executor/releases/download/v0.6.1/manticore-executor_${EXECUTOR_VERSION}_linux_${TARGET_ARCH}.tar.gz | tar -xzf - && \
  mv manticore-executor_${EXECUTOR_VERSION}_linux_${TARGET_ARCH}/manticore-executor /usr/bin && \
  rm -fr manticore-executor_${EXECUTOR_VERSION}_linux_${TARGET_ARCH} && \
  apk clean cache

# alter bash prompt
ENV PS1A="\u@manticore-backup.test:\w> "
RUN echo 'PS1=$PS1A' >> ~/.bashrc && \
  echo 'figlet -w 120 Manticore Buddy Test Kit' >> ~/.bashrc

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
