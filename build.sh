echo "Compiling necessary stuff..."
cd ./tainacan-immich
npm install
npm run build
cd ../

if [ -z "$1" ]
then
    echo "Done!"
else
    echo "Done. Moving files to destination folder: $1"
    rm -rf $1/tainacan-immich
    cp -r ./tainacan-immich $1
    echo "Cleaning some files not necessary for the plugin to work..."
    rm -f $1/tainacan-immich/package.json
    rm -f $1/tainacan-immich/package-lock.json
    rm -rf $1/tainacan-immich/node_modules
    rm -rf $1/tainacan-immich/src
    echo "Done!"
fi