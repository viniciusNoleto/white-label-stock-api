#!/usr/bin/env bash
# Lista os containers em execução e abre um shell no escolhido.
PS3="Escolha uma opção: "

options=($(docker container ls --format "{{.Names}}"))
select opt in "${options[@]}" "exit"; do
    if [[ $opt == "exit" || $REPLY == "exit" ]]; then
        break
    fi

    if [[ -n $opt ]]; then
        echo "Entrando em $opt..."
        docker exec -it "$opt" bash
        break
    fi
done
