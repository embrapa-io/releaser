#!/usr/bin/env bash

read -p "Publish? (y/N): " publish

if [ "$publish" != "${publish#[Yy]}" ]; then
  while
    read -p "Version: " version
    [[ -z $version || ! $version =~ [0-9]+\.[0-9]{2}\.[0-9]+\-[0-9]+ ]]
  do true; done

  set -xe

  # https://github.com/abiosoft/colima/discussions/273
  # https://stackoverflow.com/questions/47809904/how-to-set-architecture-for-docker-build-to-arm64
  # https://docs.docker.com/build/guide/multi-platform/

  # docker buildx create --name multiarch --driver docker-container --use
  # docker buildx inspect --bootstrap

  docker buildx build \
    --platform linux/arm64/v8,linux/amd64 \
    --builder multiarch \
    --tag embrapa/releaser \
    --tag embrapa/releaser:$version \
    --build-arg IO_RELEASER_VERSION=$version \
    --push .

  # Using official image...

  # ...in Docker Compose:
  # docker run --name releaser \
  #   -v $(pwd):/data \
  #   -v /var/run/docker.sock:/var/run/docker.sock \
  #   --restart unless-stopped -d \
  #   embrapa/releaser

  # ...in Docker Swarm:
  # docker service create --name releaser \
  #   --constraint=node.hostname==$(hostname) \
  #   --mount=type=bind,src=$(pwd),dst=/data \
  #   --mount=type=bind,src=/var/run/docker.sock,dst=/var/run/docker.sock \
  #   embrapa/releaser

  exit 0
fi

while
  PS3="Orchestrator: "

  select orchestrator in DockerCompose DockerSwarm
  do
    break
  done
  [[ -z $orchestrator ]]
do true; done

echo "You is here: $(pwd)"

while
  read -p "Directory: " path
  path=$(realpath "$path")
  [[ ! -d "$path" ]]
do true; done

set -xe

version="$(date '+0.%g.%-m-dev.')$((1 + RANDOM % 10))"

case $orchestrator in

  DockerCompose)
    docker stop releaser && docker rm releaser

    docker build -t releaser --build-arg IO_RELEASER_VERSION=$version .

    docker run --name releaser \
      -v $path:/data \
      -v /var/run/docker.sock:/var/run/docker.sock \
      --restart unless-stopped -d \
      releaser

    # docker exec -it releaser io info

    ;;

  DockerSwarm)
    docker service rm releaser || true

    docker build -t releaser --build-arg IO_RELEASER_VERSION=$version .

    docker service create --name releaser \
      --constraint=node.hostname==$(hostname) \
      --mount=type=bind,src=$path,dst=/data \
      --mount=type=bind,src=/var/run/docker.sock,dst=/var/run/docker.sock \
      releaser

    # docker exec -it $(docker ps -q -f name=releaser) io info

    ;;

esac
