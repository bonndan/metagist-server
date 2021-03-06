<?php
namespace Metagist\ServerBundle\Entity;

use Doctrine\ORM\EntityRepository;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * Repository for package ratings.
 * 
 * @author Daniel Pozzi <bonndan76@googlemail.com>
 */
class RatingRepository extends EntityRepository
{
    /**
     * Retrieves all stored meta info for the given package.
     * 
     * @param \Metagist\ServerBundle\Entity\Package $package
     * @param integer           $offset
     * @param integer           $limit
     * @return \Doctrine\Common\Collections\Collection
     */
    public function byPackage(Package $package, $offset = 0, $limit = 25)
    {
        if ($package->getId() === null) {
            throw new \RuntimeException('Package has no id.');
        }
        $builder = $this->createQueryBuilder('r')
            ->where('r.package = :package')
            ->setFirstResult($offset)
            ->orderBy('r.timeUpdated', 'DESC')
            ->setMaxResults($limit);
        
        return new ArrayCollection($builder->getQuery()->execute(array('package' => $package)));
    }
    
    /**
     * Retrieves the rating of a package by the given user.
     * 
     * @param Package $package
     * @param User    $user
     * @return Rating|null
     */
    public function byPackageAndUser(Package $package, User $user)
    {
        return $this->findOneBy(array('package' => $package, 'user' => $user));
    }
    
    /**
     * Returns all the user's ratings.
     * 
     * @param \Metagist\ServerBundle\Entity\User $user
     * @return ArrayCollection
     */
    public function byUser(User $user)
    {
        return new ArrayCollection($this->findBy(array('user' => $user)));
    }
    
    /**
     * Retrieve the latest ratings.
     * 
     * @param int $limit
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public function latest($limit = 1)
    {
        $builder = $this->createQueryBuilder('r')
            ->orderBy('r.timeUpdated', 'DESC')
            ->setMaxResults($limit);
        
        return new ArrayCollection($builder->getQuery()->execute());
    }
    
    /**
     * Saves (inserts) a single info.
     * 
     * @param \Metagist\Rating $rating
     * @return int
     */
    public function save(Rating $rating)
    {
        $this->getEntityManager()->persist($rating);
        $this->getEntityManager()->flush();
    }
    
    /**
     * Retrieves metainfo that has been updated lately.
     * 
     * @param int $limit
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public function best($limit = 25)
    {
        $builder = $this->createQueryBuilder('r')
            ->select('avg(r.rating) rateavg, r')
            ->join('r.package', 'p')
            ->groupBy('p.id')
            ->orderBy('rateavg', 'DESC')
            ->setMaxResults($limit);
        
        $result = $builder->getQuery()->execute();
        $collection = new ArrayCollection();
        foreach ($result as $data) {
            foreach ($data as $entry) {
                if ($entry instanceof Rating) {
                    $collection->add($entry->getPackage());
                }
            }
        }
        return $collection;
    }
    
    /**
     * Retrieves the average rating for a package.
     * 
     * @param \Metagist\ServerBundle\Entity\Package $package
     * @return float
     * @todo select rateavg without the package
     */
    public function getAverageForPackage(Package $package)
    {
        $builder = $this->createQueryBuilder('r')
            ->select('avg(r.rating) rateavg, r')
            ->join('r.package', 'p')
            ->groupBy('p.id')
            ->where('r.package = :package')
            ->orderBy('rateavg', 'DESC');
        
        $result = $builder->getQuery()->setHydrationMode(\Doctrine\ORM\Query::HYDRATE_SCALAR)->execute(array('package' => $package));
        return (float)$result[0]['rateavg'];
    }
}